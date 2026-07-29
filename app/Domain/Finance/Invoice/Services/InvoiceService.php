<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Repositories\InvoiceRepository;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Models\Invoice;
use App\Models\InvoiceApprovalLog;
use App\Models\Investor;
use App\Models\KlienAr;
use App\Models\OpeningBalanceDetail;
use App\Models\PembayaranAr;
use App\Models\PembayaranArLog;
use App\Models\User;
use App\Support\Helpers\InvestorIdentityMatcher;
use App\Support\Helpers\RoleHelper;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepository $repository,
        private readonly EndingBalanceService $endingBalanceService,
        private readonly FinanceNotificationService $financeNotificationService,
    ) {}

    /**
     * Guard bersama: tolak edit/hapus kalau periode Ending Balance invoice
     * ini (berdasarkan klien_ar_id+tanggal_invoice saat ini) sudah LOCKED.
     */
    private function abortIfPeriodLocked(Invoice $invoice): void
    {
        if (!$invoice->klien_ar_id || !$invoice->tanggal_invoice) {
            return;
        }

        abort_if(
            $this->endingBalanceService->isLockedForPeriod(
                $invoice->klien_ar_id,
                $invoice->tanggal_invoice->toDateString()
            ),
            422,
            'Invoice ini tidak dapat diedit/dihapus karena periodenya sudah dikunci di Ending Balance'
        );
    }

    public function paginate(array $filters = [], ?array $with = null): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, with: $with);
    }

    public function getAllForExport(array $filters = []): Collection
    {
        return $this->repository->getAll($filters);
    }

    public function findOrFail(int $id): Invoice
    {
        $invoice = $this->repository->findById($id);
        abort_if(!$invoice, 404, 'Invoice tidak ditemukan');
        return $invoice;
    }

    public function findHeaderOrFail(int $id): Invoice
    {
        $invoice = $this->repository->findHeaderById($id);
        abort_if(!$invoice, 404, 'Invoice tidak ditemukan');
        return $invoice;
    }

    public function findForPrintOrFail(int $id): Invoice
    {
        $invoice = $this->repository->findForPrintById($id);
        abort_if(!$invoice, 404, 'Invoice tidak ditemukan');
        return $invoice;
    }

    /**
     * Resolve semua investor_id yang mewakili "orang/entitas investor yang
     * sama" dengan $anchorInvestor. tb_investor ternyata hampir 1:1 dengan
     * tb_resto (dibuat per-outlet saat import) — jadi tidak bisa asumsikan
     * 1 investor_id = 1 investor asli. Lihat InvestorIdentityMatcher untuk
     * aturan pencocokan (KTP kalau tersedia di kedua sisi, fallback nama).
     */
    public function resolveMatchingInvestorIds(Investor $anchorInvestor): array
    {
        return Investor::query()
            ->select(['id', 'nama_investor', 'ktp'])
            ->get()
            ->filter(fn(Investor $inv) => InvestorIdentityMatcher::matches($anchorInvestor, $inv))
            ->pluck('id')
            ->all();
    }

    /**
     * Kandidat invoice reguler B2C (tipe_klien RESTO) milik investor yang
     * sama (lintas klien_ar/outlet, lintas investor_id yang sudah di-resolve
     * via resolveMatchingInvestorIds), dipakai fitur Bulk Print per Investor.
     *
     * Mode preview/link (dipanggil authenticated, $onlyInvoiceIds null):
     * disaring ke periode + status belum lunas + scoping PIC AR.
     * Mode public print ($onlyInvoiceIds diisi): mengabaikan semua filter
     * periode/status, cukup ambil ulang baris invoice_id persis dari token
     * supaya nilainya selalu terkini dari DB, sementara himpunan invoice-nya
     * tetap persis snapshot yang tersimpan di token.
     */
    public function getBulkB2CInvestorInvoices(
        ?array $investorIds,
        ?string $tanggalDari = null,
        ?string $tanggalSampai = null,
        ?int $picArKaryawanId = null,
        ?array $onlyInvoiceIds = null,
    ): Collection {
        $query = Invoice::with([
            'klienAr.resto',
            'klienAr.karyawanAr.perusahaan',
            'resto',
            'perusahaan',
            'karyawan.perusahaan',
            'items.barang',
            'pembayarans',
        ]);

        $sortKey = fn(Invoice $inv) => sprintf(
            '%s_%s',
            $inv->klienAr?->resto?->nama_resto ?? '',
            $inv->tanggal_invoice?->format('Y-m-d') ?? ''
        );

        if ($onlyInvoiceIds !== null) {
            return $query->whereIn('id', $onlyInvoiceIds)
                ->get()
                ->sortBy($sortKey)
                ->values();
        }

        return $query
            ->where('is_opening_balance', false)
            ->whereHas('klienAr', fn($q) => $q->where('tipe_klien', 'RESTO'))
            ->whereHas('klienAr.resto', fn($q) => $q->whereIn('investor_id', $investorIds))
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->whereRaw('(subtotal - total_pembayaran - total_penyesuaian) > 0')
            ->whereBetween('tanggal_invoice', [$tanggalDari, $tanggalSampai])
            ->when($picArKaryawanId, fn($q, $v) =>
                $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $v))
            )
            ->get()
            ->sortBy($sortKey)
            ->values();
    }

    public function getSummary(array $filters = []): array
    {
        return $this->repository->getSummary($filters);
    }

    public function getRekapKlien(array $filters = []): array
    {
        return $this->repository->getRekapKlien($filters);
    }

    public function getCarryover(int $klienArId, bool $excludeOpeningBalance = false): float
    {
        $query = Invoice::where('klien_ar_id', $klienArId)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN']);

        if ($excludeOpeningBalance) {
            $query->where('is_opening_balance', false);
        } else {
            $query->where(function ($q) {
                $q->where('is_opening_balance', false)
                    ->orWhere(function ($q2) {
                        $q2->where('is_opening_balance', true)
                            ->where('approval_status', 'APPROVED');
                    });
            });
        }

        return (float) $query
            ->selectRaw('COALESCE(SUM(GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - total_penyesuaian END)), 0) as total')
            ->value('total') ?? 0.0;
    }

    public function getMonthlyCarryover(int $klienArId, string $tanggalInvoice): float
    {
        $monthStart = Carbon::parse($tanggalInvoice)->startOfMonth()->toDateString();

        // Hanya invoice reguler — OB adalah dokumen terpisah dan tidak masuk tagihan_periode_sebelumnya
        return (float) Invoice::where('klien_ar_id', $klienArId)
            ->where('is_opening_balance', false)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where('tanggal_invoice', '>=', $monthStart)
            ->where('tanggal_invoice', '<=', $tanggalInvoice)
            ->selectRaw('COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total')
            ->value('total') ?? 0.0;
    }

    public function generateNoInvoice(KlienAr $klien, string $tanggal): string
    {
        return $this->generateConsolidatedInvoiceNo($klien, $tanggal);
    }

    public function generateOpeningBalanceNoInvoice(KlienAr $klien, string $tanggal): string
    {
        $klien->loadMissing('perusahaan');
        $raw       = $klien->perusahaan?->nama_singkatan_perusahaan ?? strtoupper($klien->kode_klien);
        $singkatan = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        $date      = Carbon::parse($tanggal);
        $now       = Carbon::now();

        return 'OB-' . $singkatan . '-' . $date->format('dmY') . $now->format('Hisv');
    }

    public function generateConsolidatedInvoiceNo(KlienAr $klien, ?string $tanggal = null): string
    {
        $klien->loadMissing('perusahaan');
        $raw         = $klien->perusahaan?->nama_singkatan_perusahaan ?? 'ABB';
        $singkatan   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        $invoiceDate = $tanggal ? Carbon::parse($tanggal) : Carbon::now();
        $now         = Carbon::now();

        return 'SI-' . $singkatan . '-' . $invoiceDate->format('dmY') . $now->format('Hisv');
    }

    public function create(InvoiceDTO $dto): Invoice
    {
        return DB::transaction(function () use ($dto) {
            $klien     = KlienAr::with('perusahaan')->findOrFail($dto->klien_ar_id);
            $carryover = $this->getMonthlyCarryover($dto->klien_ar_id, $dto->tanggal_invoice);
            $noInvoice = $this->generateConsolidatedInvoiceNo($klien, $dto->tanggal_invoice);

            $subtotal = collect($dto->items)->sum(
                fn($item) => ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0)
            );
            $totalTagihan = $subtotal + $carryover;

            $invoice = $this->repository->create([
                'no_invoice'                 => $noInvoice,
                'tanggal_invoice'            => $dto->tanggal_invoice,
                'tanggal_jatuh_tempo'        => $dto->tanggal_jatuh_tempo,
                'klien_ar_id'                => $dto->klien_ar_id,
                'resto_id'                   => $dto->resto_id,
                'perusahaan_id'              => $klien->perusahaan_id,
                'karyawan_id'                => $this->resolveInvoiceKaryawanId(auth()->user(), $klien),
                'no_surat_jalan'             => $dto->no_surat_jalan,
                'subtotal'                   => $subtotal,
                'tagihan_periode_sebelumnya' => $carryover,
                'total_tagihan'              => $totalTagihan,
                'total_pembayaran'           => 0,
                'sisa_tagihan'               => $totalTagihan,
                'status'                     => $dto->status,
                'keterangan'                 => $dto->keterangan,
                'prepared_token'             => Str::uuid()->toString(),
                'approved_token'             => Str::uuid()->toString(),
                'created_by'                 => auth()->id(),
            ]);

            foreach ($dto->items as $item) {
                $itemSubtotal = ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0);
                $invoice->items()->create([
                    'barang_id'        => $item['barang_id'] ?? null,
                    'kode_barang'      => $item['kode_barang'] ?? null,
                    'nama_barang'      => $item['nama_barang'],
                    'qty'              => $item['qty'],
                    'satuan'           => $item['satuan'] ?? null,
                    'harga_satuan'     => $item['harga_satuan'],
                    'subtotal'         => $itemSubtotal,
                    'keterangan'       => $item['keterangan'] ?? null,
                    'no_invoice_resto' => $item['no_invoice_resto'] ?? null,
                    'kode_resto'       => $item['kode_resto'] ?? null,
                    'nama_resto'       => $item['nama_resto'] ?? null,
                ]);
            }

            return $invoice->load([
                'klienAr.karyawanAr.perusahaan',
                'klienAr.resto.investor',
                'perusahaan',
                'karyawan',
                'items.barang',
                'pembayarans',
            ]);
        });
    }

    public function createOpeningBalance(array $data): Invoice
    {
        $user = auth()->user()->loadMissing('karyawan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');

        $klien = KlienAr::findOrFail($data['klien_ar_id']);

        return DB::transaction(function () use ($data, $klien, $user) {
            $saldoAwal = !empty($data['details'])
                ? collect($data['details'])->sum(fn($d) => (float) ($d['sisa_tagihan_asal'] ?? 0))
                : (float) ($data['saldo_awal'] ?? 0);

            $invoice = $this->repository->create([
                'no_invoice'                 => $data['no_invoice'],
                'tanggal_invoice'            => $data['tanggal'],
                'klien_ar_id'                => $data['klien_ar_id'],
                'perusahaan_id'              => $klien->perusahaan_id,
                'karyawan_id'                => $this->resolveInvoiceKaryawanId($user, $klien),
                'subtotal'                   => $saldoAwal,
                'tagihan_periode_sebelumnya' => 0,
                'total_tagihan'              => $saldoAwal,
                'total_pembayaran'           => 0,
                'sisa_tagihan'               => $saldoAwal,
                'status'                     => 'DRAFT',
                'approval_status'            => 'PENDING',
                'submitted_at'               => now(),
                'submitted_by'               => auth()->id(),
                'is_opening_balance'         => true,
                'keterangan'                 => $data['keterangan'] ?? 'Opening Balance',
                'prepared_token'             => Str::uuid()->toString(),
                'created_by'                 => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'SUBMITTED');

            if (!empty($data['details'])) {
                $this->syncOpeningBalanceDetails($invoice, $data['details']);
            }

            $submitted = $this->findOrFail($invoice->id);
            $this->financeNotificationService->obArSubmitted($submitted);

            return $submitted;
        });
    }

    public function updateOpeningBalance(Invoice $invoice, array $data): Invoice
    {
        $this->ensureOpeningBalance($invoice);

        abort_if(
            !($invoice->status === 'DRAFT' && $invoice->approval_status === 'REJECTED'),
            422,
            'Opening balance hanya dapat diedit setelah ditolak'
        );

        $this->abortIfPeriodLocked($invoice);

        $klien = KlienAr::findOrFail($data['klien_ar_id']);

        $saldoAwal = !empty($data['details'])
            ? collect($data['details'])->sum(fn($d) => (float) ($d['sisa_tagihan_asal'] ?? 0))
            : (float) ($data['saldo_awal'] ?? 0);

        $invoice->update([
            'no_invoice'                 => $data['no_invoice'],
            'tanggal_invoice'            => $data['tanggal'],
            'klien_ar_id'                => $data['klien_ar_id'],
            'perusahaan_id'              => $klien->perusahaan_id,
            'subtotal'                   => $saldoAwal,
            'total_tagihan'              => $saldoAwal,
            'sisa_tagihan'               => $saldoAwal - $invoice->total_pembayaran,
            'keterangan'                 => $data['keterangan'] ?? 'Opening Balance',
            'updated_by'                 => auth()->id(),
        ]);

        $invoice->openingBalanceDetails()->delete();
        if (!empty($data['details'])) {
            $this->syncOpeningBalanceDetails($invoice, $data['details']);
        }

        return $this->findOrFail($invoice->id);
    }

    private function syncOpeningBalanceDetails(Invoice $invoice, array $details): void
    {
        foreach ($details as $detail) {
            $items = $detail['items'] ?? [];

            $jumlahTagihan = !empty($items)
                ? collect($items)->sum('subtotal')
                : ($detail['jumlah_tagihan_asal'] ?? 0);

            $obDetail = $invoice->openingBalanceDetails()->create([
                'no_invoice_asal'      => $detail['no_invoice_asal'],
                'tanggal_invoice_asal' => $detail['tanggal_invoice_asal'],
                'deskripsi'            => $detail['deskripsi'],
                'jumlah_tagihan_asal'  => $jumlahTagihan,
                'sisa_tagihan_asal'    => $detail['sisa_tagihan_asal'],
                'keterangan'           => $detail['keterangan'] ?? null,
                'created_by'           => auth()->id(),
            ]);

            foreach ($items as $item) {
                $obDetail->items()->create([
                    'barang_id'    => $item['barang_id'] ?? null,
                    'kode_barang'  => $item['kode_barang'] ?? null,
                    'nama_barang'  => $item['nama_barang'],
                    'qty'          => $item['qty'],
                    'satuan'       => $item['satuan'] ?? null,
                    'harga_satuan' => $item['harga_satuan'],
                    'subtotal'     => $item['subtotal'],
                    'keterangan'   => $item['keterangan'] ?? null,
                ]);
            }
        }
    }

    public function resubmitOpeningBalance(Invoice $invoice, ?string $note = null): Invoice
    {
        $this->ensureOpeningBalance($invoice);

        abort_if(
            !$invoice->canBeResubmitted(),
            422,
            'Opening balance hanya dapat diajukan ulang jika status approval ditolak'
        );

        return DB::transaction(function () use ($invoice, $note) {
            $invoice->update([
                'approval_status' => 'PENDING',
                'submitted_at'    => now(),
                'submitted_by'    => auth()->id(),
                'approved_at'     => null,
                'approved_by'     => null,
                'rejected_at'     => null,
                'rejected_by'     => null,
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'RESUBMITTED', $note);

            return $this->findOrFail($invoice->id);
        });
    }

    public function approveOpeningBalance(Invoice $invoice, ?string $note = null): Invoice
    {
        $this->ensurePendingOpeningBalance($invoice);

        return DB::transaction(function () use ($invoice, $note) {
            $invoice->update([
                'status'          => 'TERKIRIM',
                'approval_status' => 'APPROVED',
                'approved_at'     => now(),
                'approved_by'     => auth()->id(),
                'rejected_at'     => null,
                'rejected_by'     => null,
                'approved_token'  => Str::uuid()->toString(),
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'APPROVED', $note);

            $approved = $this->findOrFail($invoice->id);

            $this->propagateCarryover($approved);

            $this->financeNotificationService->obArApproved($approved);

            return $approved;
        });
    }

    public function rejectOpeningBalance(Invoice $invoice, string $note): Invoice
    {
        $this->ensurePendingOpeningBalance($invoice);

        return DB::transaction(function () use ($invoice, $note) {
            $invoice->update([
                'status'          => 'DRAFT',
                'approval_status' => 'REJECTED',
                'approved_at'     => null,
                'approved_by'     => null,
                'rejected_at'     => now(),
                'rejected_by'     => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'REJECTED', $note);

            $rejected = $this->findOrFail($invoice->id);
            $this->financeNotificationService->obArRejected($rejected, $note);

            return $rejected;
        });
    }

    public function update(Invoice $invoice, InvoiceDTO $dto): Invoice
    {
        abort_if(
            $invoice->status !== 'DRAFT',
            422,
            'Invoice hanya dapat diedit jika berstatus DRAFT'
        );

        $this->abortIfPeriodLocked($invoice);

        $klien    = KlienAr::findOrFail($dto->klien_ar_id);
        $carryover = $invoice->tagihan_periode_sebelumnya;

        $subtotal     = collect($dto->items)->sum(
            fn($item) => ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0)
        );
        $totalTagihan = $subtotal + $carryover;

        $invoice->update([
            'no_invoice'                 => $dto->no_invoice,
            'tanggal_invoice'            => $dto->tanggal_invoice,
            'tanggal_jatuh_tempo'        => $dto->tanggal_jatuh_tempo,
            'klien_ar_id'                => $dto->klien_ar_id,
            'resto_id'                   => $dto->resto_id,
            'perusahaan_id'              => $klien->perusahaan_id,
            'no_surat_jalan'             => $dto->no_surat_jalan,
            'subtotal'                   => $subtotal,
            'total_tagihan'              => $totalTagihan,
            'sisa_tagihan'               => $totalTagihan - $invoice->total_pembayaran,
            'keterangan'                 => $dto->keterangan,
            'updated_by'                 => auth()->id(),
        ]);

        // Replace all items
        $invoice->items()->delete();
        foreach ($dto->items as $item) {
            $itemSubtotal = ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0);
            $invoice->items()->create([
                'barang_id'        => $item['barang_id'] ?? null,
                'kode_barang'      => $item['kode_barang'] ?? null,
                'nama_barang'      => $item['nama_barang'],
                'qty'              => $item['qty'],
                'satuan'           => $item['satuan'] ?? null,
                'harga_satuan'     => $item['harga_satuan'],
                'subtotal'         => $itemSubtotal,
                'keterangan'       => $item['keterangan'] ?? null,
                'no_invoice_resto' => $item['no_invoice_resto'] ?? null,
                'kode_resto'       => $item['kode_resto'] ?? null,
                'nama_resto'       => $item['nama_resto'] ?? null,
            ]);
        }

        return $invoice->fresh(['klienAr.karyawanAr.perusahaan', 'klienAr.resto.investor', 'perusahaan', 'karyawan', 'items.barang', 'pembayarans']);
    }

    public function changeStatus(Invoice $invoice, string $status): Invoice
    {
        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, status piutang belum dapat diubah'
        );

        $allowedTransitions = [
            'DRAFT'    => ['TERKIRIM'],
            'TERKIRIM' => ['SEBAGIAN', 'LUNAS'],
            'SEBAGIAN' => ['LUNAS'],
            'LUNAS'    => [],
        ];

        abort_if(
            !in_array($status, $allowedTransitions[$invoice->status] ?? []),
            422,
            "Invoice tidak dapat diubah dari status {$invoice->status} ke {$status}"
        );

        $updateData = ['status' => $status, 'updated_by' => auth()->id()];

        if ($status === 'LUNAS') {
            $updateData['sisa_tagihan'] = 0;
        }

        $invoice->update($updateData);

        $this->cascadeCarryoverToNext($invoice->fresh());

        return $invoice->fresh();
    }

    public function recalculate(Invoice $invoice): void
    {
        $totalPembayaran  = $invoice->pembayarans()->sum('jumlah_pembayaran');
        $totalPenyesuaian = (float) $invoice->total_penyesuaian;
        $subtotal         = (float) $invoice->subtotal;

        // Untuk invoice reguler, hitung ulang tagihan_periode_sebelumnya dari riwayat aktual
        // agar data yang salah (misal OB-carryover terbalik) bisa dikoreksi lewat recalculate.
        $newCarryover    = $invoice->is_opening_balance ? 0.0 : $this->sumOwnSisaBeforeInvoice($invoice);
        $newTotalTagihan = $invoice->is_opening_balance
            ? (float) $invoice->total_tagihan   // OB: total_tagihan akan dikoreksi oleh cascadeCarryoverToNext
            : $subtotal + $newCarryover;

        // Penyesuaian manual (write-off) diperlakukan setara pembayaran saat
        // menghitung sisa & status, tapi disimpan terpisah (tidak menambah total_pembayaran).
        $terbayarEfektif = (float) $totalPembayaran + $totalPenyesuaian;

        $rawSisa = max(0, $newTotalTagihan - $terbayarEfektif);
        // Regular invoice (subtotal > 0): LUNAS saat subtotal terbayar lunas.
        // OB invoice (subtotal = 0): LUNAS saat total_tagihan terbayar (perilaku lama).
        $isLunas = $subtotal > 0
            ? $terbayarEfektif >= $subtotal
            : $rawSisa <= 0;

        if ($terbayarEfektif <= 0) {
            $status      = 'TERKIRIM';
            $sisaTagihan = $rawSisa;
        } elseif ($isLunas) {
            $status      = 'LUNAS';
            $sisaTagihan = 0;
        } else {
            $status      = 'SEBAGIAN';
            $sisaTagihan = $rawSisa;
        }

        $updateData = [
            'total_pembayaran' => $totalPembayaran,
            'sisa_tagihan'     => $sisaTagihan,
            'status'           => $status,
            'updated_by'       => auth()->id(),
        ];

        if (!$invoice->is_opening_balance) {
            $updateData['tagihan_periode_sebelumnya'] = $newCarryover;
            $updateData['total_tagihan']              = $newTotalTagihan;
        }

        $invoice->update($updateData);

        $this->cascadeCarryoverToNext($invoice->fresh());

        // Invoice reguler bisa direferensikan oleh baris OB (snapshot statis) sebagai
        // "invoice periode sebelumnya". Sinkronkan snapshot tersebut agar nominal OB
        // ikut ter-recalculate saat invoice reguler dibayar / dibatalkan.
        if (!$invoice->is_opening_balance) {
            $this->syncOpeningBalanceSnapshots($invoice->fresh());
        }
    }

    /**
     * Entry point publik untuk backfill: sinkronkan snapshot OB dari satu invoice reguler.
     */
    public function resyncOpeningBalanceSnapshots(Invoice $invoice): void
    {
        if ($invoice->is_opening_balance) {
            return;
        }

        $this->syncOpeningBalanceSnapshots($invoice);
    }

    /**
     * Perbarui baris OB (tb_opening_balance_detail) yang merujuk invoice reguler ini
     * via no_invoice_asal, lalu hitung ulang OB yang terdampak.
     */
    private function syncOpeningBalanceSnapshots(Invoice $invoice): void
    {
        // Pembayaran yang berasal dari pelunasan OB (child payment) TIDAK boleh ikut
        // mengurangi snapshot sisa_tagihan_asal — kalau ikut, subtotal OB akan ter-nol-kan
        // (circular clobber). Hitung sisa "asli" invoice reguler dengan mengecualikan
        // pembayaran yang sumbernya pembayaran milik invoice OB.
        $obOriginPembayaran = $this->sumOpeningBalanceOriginPayments($invoice);

        $sisaAsal = max(0, (float) $invoice->subtotal
            - ((float) $invoice->total_pembayaran - $obOriginPembayaran)
            - (float) $invoice->total_penyesuaian);

        $details = OpeningBalanceDetail::where('no_invoice_asal', $invoice->no_invoice)
            ->whereHas('invoice', fn($q) => $q->where('klien_ar_id', $invoice->klien_ar_id))
            ->get();

        if ($details->isEmpty()) {
            return;
        }

        $affectedObIds = [];
        foreach ($details as $detail) {
            if (abs((float) $detail->sisa_tagihan_asal - $sisaAsal) < 0.01) {
                continue;
            }

            $detail->update([
                'sisa_tagihan_asal' => $sisaAsal,
                'updated_by'        => auth()->id(),
            ]);

            $affectedObIds[$detail->invoice_id] = true;
        }

        foreach (array_keys($affectedObIds) as $obId) {
            $ob = Invoice::find($obId);
            if ($ob) {
                $this->recomputeOpeningBalanceFromDetails($ob);
            }
        }
    }

    /**
     * Hitung ulang nominal OB dari sum sisa_tagihan_asal baris-barisnya.
     * Tidak memanggil recalculate()/cascade pada OB agar tidak terjadi rekursi.
     */
    private function recomputeOpeningBalanceFromDetails(Invoice $ob): void
    {
        $newSubtotal = (float) $ob->openingBalanceDetails()->sum('sisa_tagihan_asal');
        $terbayarEf  = (float) $ob->total_pembayaran + (float) $ob->total_penyesuaian;

        $rawSisa = max(0, $newSubtotal - $terbayarEf);
        $isLunas = $rawSisa <= 0;

        $updateData = [
            'subtotal'      => $newSubtotal,
            'total_tagihan' => $newSubtotal,
            'sisa_tagihan'  => $isLunas ? 0 : $rawSisa,
            'updated_by'    => auth()->id(),
        ];

        // Hanya ubah status untuk OB yang sudah disetujui (masuk financial flow).
        // OB DRAFT/PENDING tidak boleh dipaksa menjadi TERKIRIM/LUNAS.
        if ($ob->approval_status === 'APPROVED') {
            $updateData['status'] = match (true) {
                $terbayarEf <= 0 => $isLunas ? 'LUNAS' : 'TERKIRIM',
                $isLunas         => 'LUNAS',
                default          => 'SEBAGIAN',
            };
        }

        $ob->update($updateData);
    }

    public function propagateCarryover(Invoice $invoice): void
    {
        $this->cascadeCarryoverToNext($invoice);
    }

    /**
     * Dipanggil setelah import-upsert mengubah subtotal invoice yang sudah punya pembayaran.
     * Jika invoice menjadi overpaid (total_pembayaran > subtotal baru), coba otomatis:
     * - Recalculate PDM yang sudah ada, atau
     * - Buat PDM baru jika pembayaran berasal dari rekonsiliasi bank (ada bankStatementDetail).
     * Pembayaran manual (catat bayar) dibiarkan — user tangani lewat UI.
     */
    public function handleExcessPaymentAfterUpdate(Invoice $invoice): void
    {
        $invoice->loadMissing('pembayarans.bankStatementDetail');

        $pdmService = app(\App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService::class);

        foreach ($invoice->pembayarans->whereNull('sumber_pembayaran_ar_id') as $pembayaran) {
            $existingPdm = \App\Models\PendapatanDiMuka::where('sumber_pembayaran_ar_id', $pembayaran->id)
                ->whereNotIn('status', ['DIBATALKAN'])
                ->first();

            if ($existingPdm) {
                $pdmService->recalculate($existingPdm);
                continue;
            }

            $detail = $pembayaran->bankStatementDetail;
            if (!$detail) {
                continue;
            }

            // Hitung kelebihan terbaru setelah invoice diupdate
            $kelebihanFromInvoice = max(0, round((float) $invoice->total_pembayaran - (float) $invoice->total_tagihan, 2));
            $kelebihanFromBank    = max(0, round((float) $detail->kredit - (float) $pembayaran->jumlah_pembayaran, 2));
            $totalKelebihan       = max($kelebihanFromInvoice, $kelebihanFromBank);
            $dialokasi            = (float) $pembayaran->alokasiKelebihan()->sum('jumlah_pembayaran');
            $sisa                 = max(0, round($totalKelebihan - $dialokasi, 2));

            if ($sisa > 0.01) {
                try {
                    $pdmService->store($detail, $sisa, null, now()->format('Y-m-d'));
                } catch (\Throwable) {
                    // Tidak blokir proses import jika PDM gagal dibuat
                }
            }
        }
    }

    /**
     * Recalculate semua EndingBalance berstatus DRAFT milik klien yang periode-nya
     * mencakup tanggal_invoice yang baru saja berubah.
     */
    public function recalculateDraftEndingBalance(Invoice $invoice): void
    {
        $ebList = \App\Models\EndingBalance::where('klien_ar_id', $invoice->klien_ar_id)
            ->where('status', 'DRAFT')
            ->where('periode_awal', '<=', $invoice->tanggal_invoice)
            ->where('periode_akhir', '>=', $invoice->tanggal_invoice)
            ->get();

        if ($ebList->isEmpty()) {
            return;
        }

        $ebService = app(\App\Domain\Finance\EndingBalance\Services\EndingBalanceService::class);
        foreach ($ebList as $eb) {
            try {
                $ebService->recalculate($eb, auth()->id());
            } catch (\Throwable) {
                // Tidak blokir proses import jika EB recalculate gagal
            }
        }
    }

    /**
     * Total pembayaran pada invoice reguler yang BERASAL dari pelunasan OB
     * (child payment yang sumber_pembayaran_ar_id-nya menunjuk pembayaran invoice OB).
     */
    private function sumOpeningBalanceOriginPayments(Invoice $invoice): float
    {
        return (float) $invoice->pembayarans()
            ->whereHas('sumberPembayaran.invoice', fn($q) => $q->where('is_opening_balance', true))
            ->sum('jumlah_pembayaran');
    }

    /**
     * Daftar invoice reguler "periode sebelumnya" yang dirujuk rincian OB dan masih
     * bisa dilunaskan, plus dana OB yang masih tersedia untuk dialokasikan.
     * Dipakai picker pada form Catat Bayar & dialog Cocokkan Transaksi.
     */
    public function getSettleableOriginals(Invoice $ob): array
    {
        if (!$ob->is_opening_balance) {
            return ['available' => 0.0, 'invoices' => []];
        }

        $ob->loadMissing('openingBalanceDetails');
        $noAsals = $ob->openingBalanceDetails
            ->pluck('no_invoice_asal')->filter()->unique()->values();

        $invoices = [];
        if ($noAsals->isNotEmpty()) {
            $regulars = Invoice::where('klien_ar_id', $ob->klien_ar_id)
                ->where('is_opening_balance', false)
                ->whereIn('no_invoice', $noAsals)
                ->where('status', '!=', 'LUNAS')
                ->orderBy('tanggal_invoice')
                ->orderBy('id')
                ->get();

            foreach ($regulars as $inv) {
                $sisa = $this->ownSisa($inv);
                if ($sisa <= 0.01) {
                    continue;
                }
                $invoices[] = [
                    'id'              => $inv->id,
                    'no_invoice'      => $inv->no_invoice,
                    'tanggal_invoice' => $inv->tanggal_invoice?->toDateString(),
                    'sisa_tagihan'    => $sisa,
                    'status'          => $inv->status,
                ];
            }
        }

        return [
            'available' => $this->availableOpeningBalancePayment($ob),
            'invoices'  => $invoices,
        ];
    }

    /**
     * Lunaskan invoice reguler yang dipilih dari pembayaran sebuah OB.
     * Membuat child payment ber-no_referensi unik ("{ref}/OB-{n}") yang menautkan
     * ke pembayaran OB lewat sumber_pembayaran_ar_id, lalu meng-update invoice reguler
     * SECARA LANGSUNG (tanpa recalculate) agar tidak memicu syncOpeningBalanceSnapshots
     * yang akan menge-nol-kan subtotal OB.
     */
    public function settleOriginalsFromOpeningBalance(
        Invoice $ob,
        PembayaranAr $obPayment,
        array $selectedInvoiceIds
    ): void {
        if (!$ob->is_opening_balance || !$ob->isApprovedForFinanceFlow()) {
            return;
        }

        $selectedInvoiceIds = array_values(array_unique(array_map('intval', $selectedInvoiceIds)));
        if (empty($selectedInvoiceIds)) {
            return;
        }

        $available = $this->availableOpeningBalancePayment($ob);
        if ($available <= 0.01) {
            return;
        }

        $noAsals = $ob->openingBalanceDetails()
            ->pluck('no_invoice_asal')->filter()->unique()->values();
        if ($noAsals->isEmpty()) {
            return;
        }

        $regulars = Invoice::whereIn('id', $selectedInvoiceIds)
            ->where('klien_ar_id', $ob->klien_ar_id)
            ->where('is_opening_balance', false)
            ->whereIn('no_invoice', $noAsals)
            ->where('status', '!=', 'LUNAS')
            ->orderBy('tanggal_invoice')
            ->orderBy('id')
            ->get();

        foreach ($regulars as $target) {
            if ($available <= 0.01) {
                break;
            }

            // Idempoten: jangan buat pelunasan ganda dari pembayaran OB yang sama.
            $sudahAda = PembayaranAr::where('invoice_id', $target->id)
                ->where('sumber_pembayaran_ar_id', $obPayment->id)
                ->exists();
            if ($sudahAda) {
                continue;
            }

            $sisa = $this->ownSisa($target);
            if ($sisa <= 0.01) {
                continue;
            }

            $jumlah = round(min($sisa, $available), 2);
            if ($jumlah <= 0) {
                continue;
            }

            $child = PembayaranAr::create([
                'invoice_id'              => $target->id,
                'tanggal_pembayaran'      => $obPayment->tanggal_pembayaran,
                'jumlah_pembayaran'       => $jumlah,
                'metode_pembayaran'       => $obPayment->metode_pembayaran,
                'no_referensi'            => $obPayment->no_referensi
                                                ? $obPayment->no_referensi . '/OB-' . $this->nextObSettleSuffix($obPayment)
                                                : null,
                'keterangan'              => 'Pelunasan otomatis dari OB ' . $ob->no_invoice,
                'sumber_pembayaran_ar_id' => $obPayment->id,
                'created_by'              => auth()->id(),
            ]);

            PembayaranArLog::create([
                'pembayaran_ar_id' => $child->id,
                'aksi'             => 'DIBUAT',
                'actor_id'         => auth()->id(),
                'data_sesudah'     => $child->toArray(),
            ]);

            // Update invoice reguler langsung tanpa cascade/recalculate (mirror applyKelebihan).
            $fresh     = $target->fresh();
            $newTotal  = (float) $fresh->pembayarans()->sum('jumlah_pembayaran');
            $subtotal  = (float) $fresh->subtotal;
            $rawSisa   = max(0, (float) $fresh->total_tagihan - $newTotal);
            $isLunas   = $subtotal > 0 ? $newTotal >= $subtotal : $rawSisa <= 0;
            $newStatus = match (true) {
                $isLunas      => 'LUNAS',
                $newTotal > 0 => 'SEBAGIAN',
                default       => 'TERKIRIM',
            };
            $fresh->update([
                'total_pembayaran' => $newTotal,
                'sisa_tagihan'     => $isLunas ? 0 : $rawSisa,
                'status'           => $newStatus,
                'updated_by'       => auth()->id(),
            ]);

            $available = round($available - $jumlah, 2);
        }
    }

    /**
     * Sisa tagihan "milik sendiri" invoice (berbasis subtotal, mengikuti pola
     * outstanding()/applyKelebihan), bukan total_tagihan yang memuat carryover.
     */
    private function ownSisa(Invoice $invoice): float
    {
        $subtotal   = (float) $invoice->subtotal;
        $totalBayar = (float) $invoice->pembayarans()->sum('jumlah_pembayaran');

        return $subtotal > 0
            ? max(0, $subtotal - $totalBayar)
            : max(0, (float) $invoice->total_tagihan - $totalBayar);
    }

    /**
     * Dana pembayaran OB yang belum dialokasikan ke invoice reguler manapun.
     */
    private function availableOpeningBalancePayment(Invoice $ob): float
    {
        $totalBayarOb    = (float) $ob->pembayarans()->sum('jumlah_pembayaran');
        $sudahDialokasi  = (float) PembayaranAr::whereHas(
            'sumberPembayaran',
            fn($q) => $q->where('invoice_id', $ob->id)
        )->sum('jumlah_pembayaran');

        return max(0, round($totalBayarOb - $sudahDialokasi, 2));
    }

    /**
     * Suffix berikutnya untuk no_referensi child pelunasan OB ("{ref}/OB-{n}").
     */
    private function nextObSettleSuffix(PembayaranAr $obPayment): int
    {
        $prefix = $obPayment->no_referensi . '/OB-';
        $max    = 0;

        $refs = PembayaranAr::where('sumber_pembayaran_ar_id', $obPayment->id)
            ->where('no_referensi', 'LIKE', $prefix . '%')
            ->pluck('no_referensi');

        foreach ($refs as $ref) {
            $n = (int) str_replace($prefix, '', (string) $ref);
            if ($n > $max) {
                $max = $n;
            }
        }

        return $max + 1;
    }

    private function sumOwnSisaBeforeInvoice(Invoice $invoice): float
    {
        $monthStart = Carbon::parse($invoice->tanggal_invoice)->startOfMonth()->toDateString();

        // Hanya invoice reguler — OB adalah dokumen terpisah dan tidak masuk tagihan_periode_sebelumnya
        return (float) Invoice::where('klien_ar_id', $invoice->klien_ar_id)
            ->where('is_opening_balance', false)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where('tanggal_invoice', '>=', $monthStart)
            ->where(function ($q) use ($invoice) {
                $q->where('tanggal_invoice', '<', $invoice->tanggal_invoice)
                    ->orWhere(function ($q2) use ($invoice) {
                        $q2->where('tanggal_invoice', $invoice->tanggal_invoice)
                            ->where('id', '<', $invoice->id);
                    });
            })
            ->selectRaw('COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total')
            ->value('total') ?? 0.0;
    }

    /**
     * Propagasi carryover ke invoice-invoice sesudahnya (iteratif).
     *
     * Sengaja TIDAK rekursif: pada import batch besar satu klien bisa punya
     * ribuan invoice, sehingga rekursi akan menabrak batas kedalaman PHP.
     */
    private function cascadeCarryoverToNext(Invoice $invoice): void
    {
        $current = $invoice;

        while (true) {
            $nextInvoice = Invoice::where('klien_ar_id', $current->klien_ar_id)
                ->where(function ($q) use ($current) {
                    $q->where('tanggal_invoice', '>', $current->tanggal_invoice)
                        ->orWhere(function ($q2) use ($current) {
                            $q2->where('tanggal_invoice', $current->tanggal_invoice)
                                ->where('id', '>', $current->id);
                        });
                })
                ->orderBy('tanggal_invoice')
                ->orderBy('id')
                ->first();

            if (!$nextInvoice) {
                return;
            }

            // OB tidak boleh mewarisi carryover dari invoice reguler.
            // Jika OB sudah terlanjur memiliki tagihan_periode_sebelumnya yang salah, koreksi.
            // Setelah koreksi (atau jika sudah benar), selalu teruskan cascade ke invoice reguler sesudahnya.
            if ($nextInvoice->is_opening_balance) {
                if ((float) $nextInvoice->tagihan_periode_sebelumnya > 0.01) {
                    $subtotalOb   = (float) $nextInvoice->subtotal;
                    $terbayarEfOb = (float) $nextInvoice->total_pembayaran + (float) $nextInvoice->total_penyesuaian;
                    $rawSisaOb    = max(0, $subtotalOb - $terbayarEfOb);
                    $isLunasOb    = $terbayarEfOb >= $subtotalOb;

                    $nextInvoice->update([
                        'tagihan_periode_sebelumnya' => 0,
                        'total_tagihan'              => $subtotalOb,
                        'sisa_tagihan'               => $isLunasOb ? 0 : $rawSisaOb,
                        'status'                     => $terbayarEfOb <= 0 ? 'TERKIRIM' : ($isLunasOb ? 'LUNAS' : 'SEBAGIAN'),
                        'updated_by'                 => auth()->id(),
                    ]);
                }
                // Lanjutkan cascade melewati OB agar invoice reguler sesudahnya ikut diperbarui
                $current = $nextInvoice->fresh();
                continue;
            }

            $oldCarryover = (float) $nextInvoice->tagihan_periode_sebelumnya;
            $newCarryover = $this->sumOwnSisaBeforeInvoice($nextInvoice);

            if (abs($oldCarryover - $newCarryover) < 0.01) {
                return;
            }

            $newTotalTagihan      = (float) $nextInvoice->subtotal + $newCarryover;
            $subtotalNext         = (float) $nextInvoice->subtotal;
            $totalPembayaranNext  = (float) $nextInvoice->total_pembayaran;
            $totalPenyesuaianNext = (float) $nextInvoice->total_penyesuaian;
            $terbayarEfektifNext  = $totalPembayaranNext + $totalPenyesuaianNext;

            $rawSisaNext  = max(0, $newTotalTagihan - $terbayarEfektifNext);
            $isLunasNext  = $subtotalNext > 0
                ? $terbayarEfektifNext >= $subtotalNext
                : $rawSisaNext <= 0;
            $newSisaTagihan = $isLunasNext ? 0.0 : $rawSisaNext;

            $newStatus = match (true) {
                $terbayarEfektifNext <= 0 => 'TERKIRIM',
                $isLunasNext              => 'LUNAS',
                default                   => 'SEBAGIAN',
            };

            $nextInvoice->update([
                'tagihan_periode_sebelumnya' => $newCarryover,
                'total_tagihan'              => $newTotalTagihan,
                'sisa_tagihan'               => $newSisaTagihan,
                'status'                     => $newStatus,
                'updated_by'                 => auth()->id(),
            ]);

            $current = $nextInvoice->fresh();
        }
    }

    public function delete(Invoice $invoice): void
    {
        abort_if(
            $invoice->status !== 'DRAFT',
            422,
            'Hanya invoice berstatus DRAFT yang dapat dihapus'
        );

        $this->abortIfPeriodLocked($invoice);

        $prevInvoice = Invoice::where('klien_ar_id', $invoice->klien_ar_id)
            ->where(function ($q) use ($invoice) {
                $q->where('tanggal_invoice', '<', $invoice->tanggal_invoice)
                    ->orWhere(function ($q2) use ($invoice) {
                        $q2->where('tanggal_invoice', $invoice->tanggal_invoice)
                            ->where('id', '<', $invoice->id);
                    });
            })
            ->orderByDesc('tanggal_invoice')
            ->orderByDesc('id')
            ->first();

        $invoice->items()->delete();
        $this->repository->delete($invoice);

        if ($prevInvoice) {
            $this->cascadeCarryoverToNext($prevInvoice->fresh());
        }
    }

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $invoice = $this->repository->findById((int) $id);
            if (!$invoice || $invoice->status !== 'DRAFT') {
                continue;
            }
            try {
                $this->delete($invoice);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (misalnya cascade constraint)
            }
        }
        return $deleted;
    }

    private function ensureOpeningBalance(Invoice $invoice): void
    {
        abort_if(
            !$invoice->is_opening_balance,
            422,
            'Data yang dipilih bukan opening balance'
        );
    }

    private function ensurePendingOpeningBalance(Invoice $invoice): void
    {
        $this->ensureOpeningBalance($invoice);

        abort_if(
            !($invoice->status === 'DRAFT' && $invoice->approval_status === 'PENDING'),
            422,
            'Opening balance tidak berada pada status menunggu persetujuan'
        );
    }

    private function createApprovalLog(Invoice $invoice, string $action, ?string $note = null): void
    {
        InvoiceApprovalLog::create([
            'invoice_id' => $invoice->id,
            'action'     => $action,
            'actor_id'   => auth()->id(),
            'note'       => $note,
        ]);
    }

    public function resolveInvoiceKaryawanId(User $user, KlienAr $klien): int
    {
        $user->loadMissing('karyawan');

        // Jika Manager/Supervisor yang membuat dan klien punya PIC AR, gunakan karyawan AR klien
        if (RoleHelper::hasAnyRole($user, ['MANAGER', 'SUPERVISOR']) && $klien->karyawan_ar_id) {
            return $klien->karyawan_ar_id;
        }

        return $user->karyawan->id;
    }

    private function resolveInvoiceSegment(KlienAr $klien): string
    {
        return strtoupper($klien->tipe_klien ?? '') === 'RESTO' ? 'B2C' : 'B2B';
    }
}
