<?php

namespace App\Domain\Finance\PendapatanDiMuka\Services;

use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use App\Models\PendapatanDiMuka;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PendapatanDiMukaService
{
    public function store(
        BankStatementDetail $detail,
        float $jumlah,
        ?string $keterangan,
        string $tanggalPencatatan
    ): PendapatanDiMuka {
        $pembayaran = $detail->pembayaranAr;
        abort_if(!$pembayaran, 422, 'Transaksi belum memiliki pembayaran yang dicocokkan.');

        $inv = $pembayaran->invoice;
        abort_if(!$inv, 422, 'Pembayaran tidak memiliki invoice.');

        // Hitung sisa kelebihan — sama persis dengan logika di BankStatementService::getDetail().
        // total_penyesuaian (CN/DN) ikut ditambahkan karena Credit Note mengurangi
        // outstanding invoice dengan cara yang sama seperti pembayaran.
        $kelebihanFromInvoice = max(0, round((float) $inv->total_pembayaran - (float) $inv->total_tagihan + (float) $inv->total_penyesuaian, 2));
        $kelebihanFromBank    = max(0, round((float) $detail->kredit - (float) $pembayaran->jumlah_pembayaran, 2));
        $total                = max($kelebihanFromInvoice, $kelebihanFromBank);
        $dialokasi            = (float) $pembayaran->alokasiKelebihan()->sum('jumlah_pembayaran');
        $sisa                 = max(0, round($total - $dialokasi, 2));

        abort_if($sisa <= 0.01, 422, 'Tidak ada sisa kelebihan bayar untuk dicatat sebagai Pendapatan di Muka.');
        abort_if(
            $jumlah > $sisa + 0.01,
            422,
            'Jumlah melebihi sisa kelebihan (Rp ' . number_format($sisa, 0, ',', '.') . ').'
        );

        $inv->loadMissing('klienAr.resto.investor');
        $investorId = $inv->klienAr?->resto?->investor_id;
        $klienArId  = $inv->klien_ar_id;

        return PendapatanDiMuka::updateOrCreate(
            ['sumber_pembayaran_ar_id' => $pembayaran->id],
            [
                'bank_statement_detail_id' => $detail->id,
                'investor_id'              => $investorId,
                'klien_ar_id'              => $klienArId,
                'jumlah'                   => $jumlah,
                'tanggal_pencatatan'       => $tanggalPencatatan,
                'keterangan'               => $keterangan,
                'status'                   => 'AKTIF',
                'updated_by'               => auth()->user()?->id,
            ]
        );
    }

    public function cancel(PendapatanDiMuka $pdm): void
    {
        abort_if($pdm->status === 'DIBATALKAN', 422, 'Pendapatan di Muka ini sudah dibatalkan.');

        $pdm->update(['status' => 'DIBATALKAN']);
    }

    public function gunakan(PendapatanDiMuka $pdm, int $invoiceId, float $jumlah, ?string $keterangan): void
    {
        abort_if($pdm->status !== 'AKTIF', 422, 'PDM ini sudah tidak aktif.');

        $sumber = $pdm->sumberPembayaran;
        abort_if(!$sumber, 422, 'Sumber pembayaran tidak ditemukan.');

        // Hitung sisa PDM: jumlah PDM dikurangi alokasi yang sudah dibuat via PDM (suffix /PDM-)
        $sudahDigunakan = (float) $sumber->alokasiKelebihan()
            ->where('no_referensi', 'LIKE', '%/PDM-%')
            ->sum('jumlah_pembayaran');
        $sisa = max(0, round((float) $pdm->jumlah - $sudahDigunakan, 2));

        abort_if($sisa <= 0.01, 422, 'PDM sudah habis terpakai.');
        abort_if(
            $jumlah > $sisa + 0.01,
            422,
            'Jumlah melebihi sisa PDM (Rp ' . number_format($sisa, 0, ',', '.') . ').'
        );

        $invoice = Invoice::findOrFail($invoiceId);
        abort_if((int) $invoice->klien_ar_id !== (int) $pdm->klien_ar_id, 422, 'Invoice bukan milik klien yang sama dengan PDM.');
        abort_if($invoice->status === 'LUNAS', 422, 'Invoice sudah lunas.');
        abort_if($invoice->is_opening_balance && $invoice->approval_status !== 'APPROVED', 422, 'Opening Balance belum disetujui.');

        // Tentukan suffix /PDM-N berikutnya
        $prefix     = ($sumber->no_referensi ?? '') . '/PDM-';
        $maxSuffix  = $sumber->alokasiKelebihan()
            ->where('no_referensi', 'LIKE', $prefix . '%')
            ->get(['no_referensi'])
            ->map(function ($a) use ($prefix) {
                $n = substr($a->no_referensi ?? '', strlen($prefix));
                return is_numeric($n) ? (int) $n : 0;
            })
            ->max() ?? 0;
        $nextSuffix = $maxSuffix + 1;

        DB::transaction(function () use ($sumber, $invoice, $jumlah, $keterangan, $nextSuffix, $pdm, $sisa) {
            PembayaranAr::create([
                'invoice_id'              => $invoice->id,
                'tanggal_pembayaran'      => $sumber->tanggal_pembayaran,
                'jumlah_pembayaran'       => $jumlah,
                'metode_pembayaran'       => $sumber->metode_pembayaran,
                'no_referensi'            => $sumber->no_referensi
                                                ? $sumber->no_referensi . '/PDM-' . $nextSuffix
                                                : null,
                'keterangan'              => $keterangan ?? 'Pelunasan dari Pendapatan di Muka',
                'sumber_pembayaran_ar_id' => $sumber->id,
                'created_by'              => auth()->user()?->id,
            ]);

            // Update status invoice target
            $fresh    = $invoice->fresh();
            $newTotal = (float) $fresh->pembayarans()->sum('jumlah_pembayaran');
            $subtotal = (float) $fresh->subtotal;
            $rawSisa  = max(0, (float) $fresh->total_tagihan - $newTotal);

            // OB (subtotal=0): LUNAS saat total_tagihan lunas. Invoice biasa: LUNAS saat subtotal terbayar.
            $isLunas = $subtotal > 0 ? $newTotal >= $subtotal : $rawSisa <= 0;

            $newStatus = match (true) {
                $newTotal <= 0 => 'TERKIRIM',
                $isLunas       => 'LUNAS',
                default        => 'SEBAGIAN',
            };
            $newSisa = $isLunas ? 0.0 : $rawSisa;

            $fresh->update([
                'total_pembayaran' => $newTotal,
                'sisa_tagihan'     => $newSisa,
                'status'           => $newStatus,
                'updated_by'       => auth()->user()?->id,
            ]);

            // Tandai PDM TERPAKAI jika sisa habis
            if (($sisa - $jumlah) < 0.01) {
                $pdm->update(['status' => 'TERPAKAI', 'updated_by' => auth()->user()?->id]);
            }
        });
    }

    public function recalculate(PendapatanDiMuka $pdm): void
    {
        if ($pdm->status === 'DIBATALKAN') {
            return;
        }

        $sumber = $pdm->sumberPembayaran;
        if (!$sumber) {
            return;
        }

        $inv    = $sumber->invoice;
        $detail = $sumber->bankStatementDetail;

        $kelebihanFromInvoice = $inv
            ? max(0, round((float) $inv->total_pembayaran - (float) $inv->total_tagihan + (float) $inv->total_penyesuaian, 2))
            : 0.0;
        $kelebihanFromBank = $detail
            ? max(0, round((float) $detail->kredit - (float) $sumber->jumlah_pembayaran, 2))
            : 0.0;
        $totalKelebihan = max($kelebihanFromInvoice, $kelebihanFromBank);

        $aloNonPdm = (float) $sumber->alokasiKelebihan()
            ->where(function ($q) {
                $q->whereNull('no_referensi')
                  ->orWhere('no_referensi', 'NOT LIKE', '%/PDM-%');
            })
            ->sum('jumlah_pembayaran');

        $newJumlah = max(0.0, round($totalKelebihan - $aloNonPdm, 2));

        $sudahDigunakan = (float) $sumber->alokasiKelebihan()
            ->where('no_referensi', 'LIKE', '%/PDM-%')
            ->sum('jumlah_pembayaran');

        $newJumlah = max($newJumlah, $sudahDigunakan);

        $newStatus = ($sudahDigunakan >= ($newJumlah - 0.01)) ? 'TERPAKAI' : 'AKTIF';

        $updates = ['updated_by' => auth()->id()];
        if (abs((float) $pdm->jumlah - $newJumlah) > 0.01) {
            $updates['jumlah'] = $newJumlah;
        }
        if ($pdm->status !== $newStatus) {
            $updates['status'] = $newStatus;
        }

        if (count($updates) > 1) {
            $pdm->update($updates);
        }
    }

    public function getReport(array $filters): array
    {
        $query = PendapatanDiMuka::query()
            ->with([
                'klienAr',
                'investor',
                'sumberPembayaran.alokasiKelebihan',
                'createdBy.karyawan',
            ])
            ->when(
                $filters['tanggal_dari'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '>=', $v)
            )
            ->when(
                $filters['tanggal_sampai'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '<=', $v)
            )
            ->when(
                $filters['investor_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('investor_id', $v)
            )
            ->when(
                $filters['klien_ar_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('klien_ar_id', $v)
            )
            ->when(
                $filters['status'] ?? null,
                fn(Builder $q, string $v) => $q->where('status', $v)
            )
            ->when(
                $filters['pic_ar_karyawan_id'] ?? null,
                fn(Builder $q, int $v) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $v))
            );

        $paginator = (clone $query)
            ->latest('tanggal_pencatatan')
            ->paginate($filters['per_page'] ?? 20);

        $totalAktif     = (clone $query)->where('status', 'AKTIF')->sum('jumlah');
        $totalDibatalkan = (clone $query)->where('status', 'DIBATALKAN')->sum('jumlah');
        $jumlahRecord   = (clone $query)->count();

        return [
            'data'     => $paginator->through(fn($pdm) => $this->formatRow($pdm)),
            'meta'     => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'summary'  => [
                'total_aktif'      => (float) $totalAktif,
                'total_dibatalkan' => (float) $totalDibatalkan,
                'jumlah_record'    => $jumlahRecord,
            ],
        ];
    }

    /**
     * Seluruh baris (tanpa paginasi) dalam bentuk array yang sama dengan
     * getReport() — dipakai Export Data supaya kolomnya identik dengan layar.
     */
    public function getAllFormatted(array $filters): array
    {
        return PendapatanDiMuka::query()
            ->with(['klienAr', 'investor', 'sumberPembayaran.alokasiKelebihan', 'createdBy.karyawan'])
            ->when(
                $filters['tanggal_dari'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '>=', $v)
            )
            ->when(
                $filters['tanggal_sampai'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '<=', $v)
            )
            ->when(
                $filters['investor_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('investor_id', $v)
            )
            ->when(
                $filters['klien_ar_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('klien_ar_id', $v)
            )
            ->when(
                $filters['status'] ?? null,
                fn(Builder $q, string $v) => $q->where('status', $v)
            )
            ->when(
                $filters['pic_ar_karyawan_id'] ?? null,
                fn(Builder $q, int $v) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $v))
            )
            ->latest('tanggal_pencatatan')
            ->get()
            ->map(fn(PendapatanDiMuka $pdm) => $this->formatRow($pdm))
            ->values()
            ->all();
    }

    public function getAll(array $filters): \Illuminate\Database\Eloquent\Collection
    {
        return PendapatanDiMuka::query()
            ->with(['klienAr', 'investor', 'sumberPembayaran', 'createdBy.karyawan'])
            ->when(
                $filters['tanggal_dari'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '>=', $v)
            )
            ->when(
                $filters['tanggal_sampai'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '<=', $v)
            )
            ->when(
                $filters['investor_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('investor_id', $v)
            )
            ->when(
                $filters['klien_ar_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('klien_ar_id', $v)
            )
            ->when(
                $filters['status'] ?? null,
                fn(Builder $q, string $v) => $q->where('status', $v)
            )
            ->when(
                $filters['pic_ar_karyawan_id'] ?? null,
                fn(Builder $q, int $v) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $v))
            )
            ->latest('tanggal_pencatatan')
            ->get();
    }

    private function formatRow(PendapatanDiMuka $pdm): array
    {
        $sudahDigunakan = (float) ($pdm->sumberPembayaran?->alokasiKelebihan
            ->filter(fn($a) => str_contains($a->no_referensi ?? '', '/PDM-'))
            ->sum('jumlah_pembayaran') ?? 0);
        $sisaPdm = max(0, round((float) $pdm->jumlah - $sudahDigunakan, 2));

        return [
            'id'                  => $pdm->id,
            'tanggal_pencatatan'  => $pdm->tanggal_pencatatan?->toDateString(),
            'klien'               => $pdm->klienAr?->nama_klien,
            'klien_ar_id'         => $pdm->klien_ar_id,
            'investor'            => $pdm->investor?->nama_investor,
            'no_referensi_sumber' => $pdm->sumberPembayaran?->no_referensi,
            'jumlah'              => (float) $pdm->jumlah,
            'sisa_pdm'            => $sisaPdm,
            'status'              => $pdm->status,
            'keterangan'          => $pdm->keterangan,
            'created_by'          => $pdm->createdBy?->name,
        ];
    }
}
