<?php

namespace App\Domain\Finance\Invoice\Repositories;

use App\Models\Invoice;
use App\Models\PembayaranAr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InvoiceRepository
{
    public function paginate(array $filters = [], int $perPage = 15, ?array $with = null): LengthAwarePaginator
    {
        if (isset($filters['per_page']) && is_numeric($filters['per_page'])) {
            $perPage = max(1, (int) $filters['per_page']);
        }

        $relations = $with ?? ['klienAr' => fn($q) => $q->withTrashed(), 'resto'];

        return $this->applyFilters(Invoice::with($relations), $filters)
            ->latest('tanggal_invoice')
            ->paginate($perPage);
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->applyFilters(
            Invoice::with([
                'klienAr'                  => fn($q) => $q->withTrashed()->with('resto.perusahaan'),
                'resto',
                'perusahaan',
                'createdBy',
                'openingBalanceDetails.items',
            ]),
            $filters
        )
            ->latest('tanggal_invoice')
            ->get();
    }

    /**
     * Hanya ID invoice yang cocok filter — dipakai untuk hitung jumlah baris
     * export tanpa hydrate model penuh (lihat getSummary() untuk pola serupa).
     */
    public function getIdsForExport(array $filters = []): Collection
    {
        return $this->applyFilters(Invoice::query(), $filters)->pluck('id');
    }

    /**
     * Header saja untuk halaman detail — TANPA items/pembayarans/openingBalanceDetails/
     * approvalLogs/endingBalanceKoreksi, yang di-fetch terpisah lewat endpoint lazy-nya
     * masing-masing. Jangan dipakai untuk alur yang butuh child collections (pakai
     * findById() untuk itu).
     */
    public function findHeaderById(int $id): ?Invoice
    {
        return Invoice::with([
            'klienAr.perusahaan',
            'klienAr.karyawanAr.perusahaan',
            'klienAr.resto.investor',
            'resto',
            'perusahaan',
            'karyawan.perusahaan',
            'createdBy',
            'submittedBy',
            'approvedBy',
            'rejectedBy',
            'updatedBy',
            'lastDecisionLog',
        ])->find($id);
    }

    public function findById(int $id): ?Invoice
    {
        return Invoice::with([
            'klienAr.perusahaan',
            'klienAr.karyawanAr.perusahaan',
            'klienAr.resto.investor',
            'resto',
            'perusahaan',
            'karyawan.perusahaan',
            'items.barang',
            'openingBalanceDetails.items.barang',
            'pembayarans.createdBy',
            'createdBy',
            'submittedBy',
            'approvedBy',
            'rejectedBy',
            'updatedBy',
            'approvalLogs.actor',
            'endingBalanceKoreksi.submittedBy',
            'endingBalanceKoreksi.spv',
            'endingBalanceKoreksi.manager',
            'endingBalanceKoreksi.items',
        ])->find($id);
    }

    /**
     * Relasi yang benar-benar dipakai template finance.invoice-print, dipakai
     * satu kali oleh InvoiceController::print() alih-alih findById() penuh
     * (yang membawa approvalLogs, endingBalanceKoreksi tanpa filter, dll)
     * lalu di-load() ulang dengan set relasi yang berbeda.
     */
    public function findForPrintById(int $id): ?Invoice
    {
        return Invoice::with([
            'klienAr.karyawanAr.perusahaan',
            'klienAr.perusahaan',
            'klienAr.resto.investor',
            'perusahaan',
            'karyawan.perusahaan',
            'resto',
            'items.barang',
            'openingBalanceDetails.items.barang',
            'pembayarans',
            'createdBy.karyawan',
            'submittedBy.karyawan',
            'approvedBy.karyawan',
            'endingBalanceKoreksi' => fn($q) => $q
                ->whereIn('tipe', ['CREDIT_NOTE', 'DEBIT_NOTE'])
                ->where('status', 'APPROVED'),
        ])->find($id);
    }

    public function create(array $data): Invoice
    {
        return Invoice::create($data);
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);
        return $invoice->fresh([
            'klienAr',
            'perusahaan',
            'karyawan',
            'items',
            'openingBalanceDetails.items.barang',
            'pembayarans',
            'createdBy',
            'submittedBy',
            'approvedBy',
            'rejectedBy',
            'updatedBy',
            'approvalLogs.actor',
        ]);
    }

    public function delete(Invoice $invoice): bool
    {
        return (bool) $invoice->forceDelete();
    }

    public function getSummary(array $filters = []): array
    {
        // total_tagihan/sisa_tagihan per invoice sudah kumulatif (mencakup carry-over
        // dari invoice sebelumnya milik klien yang sama dalam bulan yang sama), jadi
        // tidak boleh langsung di-SUM lintas invoice — akan menghitung ulang nominal
        // yang sama berkali-kali. Pakai subtotal (murni milik invoice ini) dan sisa
        // milik invoice ini sendiri (bukan field sisa_tagihan yang kumulatif).
        $result = $this->applyFilters(Invoice::query(), $filters)
            ->selectRaw('
                COUNT(*) as total_invoice,
                COALESCE(SUM(subtotal), 0) as total_tagihan,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total_sisa
            ')
            ->first();

        return [
            'total_invoice'    => (int) ($result?->total_invoice ?? 0),
            'total_tagihan'    => (float) ($result?->total_tagihan ?? 0),
            'total_pembayaran' => (float) ($result?->total_pembayaran ?? 0),
            'total_sisa'       => (float) ($result?->total_sisa ?? 0),
        ];
    }

    public function getRekapKlien(array $filters = []): array
    {
        $today = now()->toDateString();

        // sisa_tagihan (kumulatif, termasuk carry-over dari invoice lain milik klien
        // yang sama dalam bulan yang sama) tidak boleh langsung di-SUM lintas invoice
        // di GROUP BY klien_ar_id ini — akan double-count. Pakai ulang milik invoice
        // itu sendiri (subtotal - pembayaran - penyesuaian) sebagai gantinya.
        $ownSisa = 'GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)';

        $rows = $this->applyFilters(Invoice::query()->with(['klienAr.perusahaan', 'klienAr.karyawanAr.perusahaan', 'klienAr.resto']), $filters)
            ->selectRaw("
                klien_ar_id,
                COUNT(*) as total_invoice,
                COALESCE(SUM(subtotal), 0) as total_tagihan,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM($ownSisa), 0) as sisa_tagihan,
                SUM(CASE WHEN $ownSisa > 0 THEN 1 ELSE 0 END) as outstanding_invoice,
                SUM(CASE WHEN $ownSisa > 0 AND tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN 1 ELSE 0 END) as overdue_invoice,
                COALESCE(SUM(CASE WHEN $ownSisa > 0 AND tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN $ownSisa ELSE 0 END), 0) as overdue_amount,
                MIN(CASE WHEN $ownSisa > 0 THEN tanggal_invoice ELSE NULL END) as invoice_tertua_tanggal,
                MIN(CASE WHEN $ownSisa > 0 THEN tanggal_jatuh_tempo ELSE NULL END) as jatuh_tempo_terdekat,
                SUM(CASE WHEN status = \"DRAFT\"    THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = \"TERKIRIM\" THEN 1 ELSE 0 END) as terkirim,
                SUM(CASE WHEN status = \"SEBAGIAN\" THEN 1 ELSE 0 END) as sebagian,
                SUM(CASE WHEN status = \"LUNAS\"    THEN 1 ELSE 0 END) as lunas
            ", [$today, $today])
            ->groupBy('klien_ar_id')
            ->get();

        $klienIds = $rows->pluck('klien_ar_id')->filter()->values();

        $lastPayments = $klienIds->isEmpty()
            ? collect()
            : PembayaranAr::query()
                ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
                ->whereIn('tb_invoice.klien_ar_id', $klienIds)
                ->select(
                    'tb_invoice.klien_ar_id',
                    'tb_pembayaran_ar.tanggal_pembayaran',
                    'tb_pembayaran_ar.jumlah_pembayaran',
                    'tb_pembayaran_ar.metode_pembayaran',
                    'tb_pembayaran_ar.no_referensi',
                )
                ->orderBy('tb_invoice.klien_ar_id')
                ->orderByDesc('tb_pembayaran_ar.tanggal_pembayaran')
                ->orderByDesc('tb_pembayaran_ar.id')
                ->get()
                ->unique('klien_ar_id')
                ->keyBy('klien_ar_id');

        return $rows->map(function ($row) use ($lastPayments) {
            $klien       = $row->klienAr;
            $lastPayment = $lastPayments->get($row->klien_ar_id);
            $totalTagih  = (float) $row->total_tagihan;
            $totalBayar  = (float) $row->total_pembayaran;

            return [
                'klien_id'        => $klien?->id,
                'kode_klien'      => $klien?->kode_klien,
                'nama_klien'      => $klien?->nama_klien,
                'nama_resto'      => $klien?->resto?->nama_resto,
                'tipe_klien'      => $klien?->tipe_klien,
                'perusahaan'      => $klien?->perusahaan?->nama_singkatan_perusahaan,
                'pic_ar'          => $klien?->karyawanAr?->nama_karyawan,
                'total_invoice'   => (int) $row->total_invoice,
                'total_tagihan'   => $totalTagih,
                'total_pembayaran'=> $totalBayar,
                'sisa_tagihan'    => (float) $row->sisa_tagihan,
                'collection_rate'  => $totalTagih > 0 ? round(($totalBayar / $totalTagih) * 100, 2) : 0,
                'outstanding_invoice'     => (int) $row->outstanding_invoice,
                'overdue_invoice'         => (int) $row->overdue_invoice,
                'overdue_amount'          => (float) $row->overdue_amount,
                'invoice_tertua_tanggal'  => $row->invoice_tertua_tanggal,
                'jatuh_tempo_terdekat'    => $row->jatuh_tempo_terdekat,
                'pembayaran_terakhir_tanggal' => $lastPayment?->tanggal_pembayaran?->format('Y-m-d'),
                'pembayaran_terakhir_nominal' => $lastPayment ? (float) $lastPayment->jumlah_pembayaran : 0,
                'pembayaran_terakhir_metode'  => $lastPayment?->metode_pembayaran,
                'pembayaran_terakhir_ref'     => $lastPayment?->no_referensi,
                'draft'           => (int) $row->draft,
                'terkirim'        => (int) $row->terkirim,
                'sebagian'        => (int) $row->sebagian,
                'lunas'           => (int) $row->lunas,
            ];
        })->values()->all();
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(array_key_exists('is_opening_balance', $filters), fn($q) =>
                $q->where('is_opening_balance', $filters['is_opening_balance'])
            )
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('no_invoice', 'like', "%{$v}%")
                ->orWhereHas('klienAr', fn($q) => $q
                    ->where('nama_klien', 'like', "%{$v}%")
                    ->orWhere('kode_klien', 'like', "%{$v}%")
                )
                ->orWhereHas('resto', fn($q) => $q->where('nama_resto', 'like', "%{$v}%"))
                ->orWhereHas('klienAr.resto', fn($q) => $q->where('nama_resto', 'like', "%{$v}%"))
                ->orWhereHas('items', fn($q) => $q
                    ->where('no_invoice_resto', 'like', "%{$v}%")
                    ->orWhere('nama_resto', 'like', "%{$v}%")
                )
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when($filters['karyawan_id'] ?? null, fn($q, $v) => $q->where('karyawan_id', $v))
            ->when($filters['pic_ar_karyawan_id'] ?? null, fn($q, $v) =>
                $q->whereHas('klienAr', fn($q) => $q->withTrashed()->where('karyawan_ar_id', $v))
            )
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['approval_status'] ?? null, fn($q, $v) => $q->where('approval_status', $v))
            ->when($filters['periode_bulan'] ?? null, fn($q, $v) =>
                $q->whereMonth('tanggal_invoice', (int) $v)
            )
            ->when($filters['periode_tahun'] ?? null, fn($q, $v) =>
                $q->whereYear('tanggal_invoice', (int) $v)
            )
            ->when($filters['tanggal_dari'] ?? null, fn($q, $v) =>
                $q->where('tanggal_invoice', '>=', $v)
            )
            ->when($filters['tanggal_sampai'] ?? null, fn($q, $v) =>
                $q->where('tanggal_invoice', '<=', $v)
            )
            ->when($filters['segment'] ?? null, fn($q, $v) =>
                $q->whereHas('klienAr', fn($q) => $q
                    ->withTrashed()
                    ->whereIn('tipe_klien', $this->resolveSegmentTypes($v)))
            );
    }

    private function resolveSegmentTypes(string $segment): array
    {
        return match(strtoupper($segment)) {
            'B2B'   => ['PT'],
            'B2C'   => ['RESTO'],
            default => ['PT', 'RESTO'],
        };
    }
}
