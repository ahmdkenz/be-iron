<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Services;

use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Exceptions\DuplicateStatementException;
use App\Domain\Finance\RekonsiliasiBankStatement\Parsers\BankParserFactory;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use App\Models\PendapatanDiMuka;
use App\Support\Helpers\RoleHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BankStatementService
{
    public function __construct(
        private readonly PembayaranArService $pembayaranArService,
    ) {}

    public function upload(UploadedFile $file, string $bankType, int $userId, bool $force = false): BankStatement
    {
        $parser = BankParserFactory::make($bankType);
        $rows   = $parser->parse($file->getPathname());

        if (empty($rows)) {
            throw new \RuntimeException('File tidak mengandung transaksi yang dapat dibaca. Pastikan format file sesuai dengan bank yang dipilih.');
        }

        $tanggalList  = array_column($rows, 'tanggal');
        sort($tanggalList);
        $periodeAwal  = $tanggalList[0];
        $periodeAkhir = end($tanggalList);

        $existing = BankStatement::where('bank_type', $bankType)
            ->where('periode_awal', '<=', $periodeAkhir)
            ->where('periode_akhir', '>=', $periodeAwal)
            ->first();

        if ($existing && !$force) {
            throw new DuplicateStatementException($existing);
        }

        return DB::transaction(function () use ($rows, $bankType, $file, $userId, $existing, $periodeAwal, $periodeAkhir) {
            if ($existing) {
                $existing->details()->delete();
                $existing->delete();
            }

            $statement = BankStatement::create([
                'bank_type'       => $bankType,
                'nama_file'       => $file->getClientOriginalName(),
                'periode_awal'    => $periodeAwal,
                'periode_akhir'   => $periodeAkhir,
                'total_transaksi' => count($rows),
                'total_kredit'    => array_sum(array_column($rows, 'kredit')),
                'jumlah_matched'  => 0,
                'jumlah_unmatched'=> 0,
                'uploaded_by'     => $userId,
            ]);

            foreach ($rows as $row) {
                BankStatementDetail::create([
                    'bank_statement_id' => $statement->id,
                    'tanggal'           => $row['tanggal'],
                    'waktu_transaksi'   => $row['waktu_transaksi'] ?? null,
                    'keterangan'        => $row['keterangan'],
                    'no_referensi'      => $row['no_referensi'] ?? null,
                    'debit'             => $row['debit'],
                    'kredit'            => $row['kredit'],
                    'saldo'             => $row['saldo'],
                    'status_cocok'      => $row['kredit'] == 0 ? 'DIABAIKAN' : 'UNMATCHED',
                    'pembayaran_ar_id'  => null,
                ]);
            }

            $this->autoMatch($statement);

            return $statement->fresh();
        });
    }

    public function autoMatch(BankStatement $statement, bool $onlyUnmatched = false): void
    {
        $usedPembayaranIds = BankStatementDetail::where('bank_statement_id', $statement->id)
            ->where('status_cocok', 'MATCHED')
            ->whereNotNull('pembayaran_ar_id')
            ->pluck('pembayaran_ar_id')
            ->toArray();

        $query = BankStatementDetail::where('bank_statement_id', $statement->id)
            ->where('kredit', '>', 0)
            ->orderBy('tanggal');

        if ($onlyUnmatched) {
            $query->where('status_cocok', 'UNMATCHED');
        }

        $details = $query->get();

        foreach ($details as $detail) {
            if (empty(trim((string) $detail->no_referensi))) {
                $detail->status_cocok     = 'UNMATCHED';
                $detail->pembayaran_ar_id = null;
                $detail->save();
                continue;
            }

            $matched = PembayaranAr::where('no_referensi', $detail->no_referensi)
                ->whereNotIn('id', $usedPembayaranIds)
                ->first();

            if ($matched) {
                $detail->status_cocok     = 'MATCHED';
                $detail->pembayaran_ar_id = $matched->id;
                $detail->matched_by       = $statement->uploaded_by;
                $usedPembayaranIds[]      = $matched->id;
            } else {
                $detail->status_cocok     = 'UNMATCHED';
                $detail->pembayaran_ar_id = null;
            }

            $detail->save();
        }

        $this->refreshCounter($statement->id);
        $statement->refresh();
    }

    public function getDetail(int $bankStatementId): array
    {
        $statement = BankStatement::with('uploader.karyawan')->findOrFail($bankStatementId);

        $details = BankStatementDetail::with($this->detailEagerLoads())
            ->where('bank_statement_id', $bankStatementId)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get()
            ->map(fn($d) => $this->formatDetailRow($d))
            ->all();

        return [
            ...$this->getHeader($statement),
            'details' => $details,
        ];
    }

    public function getHeader(BankStatement $statement): array
    {
        return [
            'id'               => $statement->id,
            'bank_type'        => $statement->bank_type,
            'nama_file'        => $statement->nama_file,
            'periode_awal'     => $statement->periode_awal?->format('d-m-Y'),
            'periode_akhir'    => $statement->periode_akhir?->format('d-m-Y'),
            'total_transaksi'  => $statement->total_transaksi,
            'total_kredit'     => $statement->total_kredit,
            'jumlah_matched'   => $statement->jumlah_matched,
            'jumlah_unmatched' => $statement->jumlah_unmatched,
            'uploaded_by'      => $statement->uploader?->name,
            'created_at'       => $statement->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }

    public function paginateDetails(BankStatement $statement, ?string $status, int $page, int $perPage): array
    {
        $query = BankStatementDetail::with($this->detailEagerLoads())
            ->where('bank_statement_id', $statement->id);

        if ($status && $status !== 'SEMUA') {
            $query->where('status_cocok', $status);
        }

        $paginator = $query->orderBy('tanggal')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

        return [
            'header' => $this->getHeader($statement),
            'rows'   => collect($paginator->items())->map(fn($d) => $this->formatDetailRow($d))->all(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ];
    }

    private function detailEagerLoads(): array
    {
        return [
            'pembayaranAr.invoice.klienAr',
            'pembayaranAr.alokasiKelebihan.invoice.klienAr',
            'pembayaranAr.alokasiKelebihan.createdBy.karyawan',
            'matchedBy.karyawan',
            'postedBy:id,username,karyawan_id',
            'postedBy.karyawan:id,nama_karyawan',
        ];
    }

    private function formatDetailRow(BankStatementDetail $d): array
    {
        $pembayaran  = $d->pembayaranAr;
        $invoice     = $pembayaran?->invoice;
        $selisihBank = $pembayaran
            ? round($d->kredit - (float) $pembayaran->jumlah_pembayaran, 2)
            : null;

        $kelebihanBayar = null;
        if ($invoice) {
            $kelebihanFromInvoice = max(0, round((float) $invoice->total_pembayaran - (float) $invoice->total_tagihan, 2));
            $kelebihanFromBank    = max(0, round($d->kredit - (float) $pembayaran->jumlah_pembayaran, 2));
            $total                = max($kelebihanFromInvoice, $kelebihanFromBank);
            if ($total > 0) {
                $dialokasi = (float) $pembayaran->alokasiKelebihan->sum('jumlah_pembayaran');
                $pdm       = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $pembayaran->id)->first();
                $kelebihanBayar = [
                    'total'           => $total,
                    'sudah_dialokasi' => round($dialokasi, 2),
                    'sisa'            => max(0, round($total - $dialokasi, 2)),
                    'riwayat'         => $pembayaran->alokasiKelebihan->map(fn($p) => [
                        'id'         => $p->id,
                        'jumlah'     => $p->jumlah_pembayaran,
                        'no_invoice' => $p->invoice?->no_invoice,
                        'klien'      => $p->invoice?->klienAr?->nama_klien,
                        'keterangan' => $p->keterangan,
                        'created_by' => $p->createdBy?->name,
                        'tanggal'    => $p->tanggal_pembayaran?->format('d-m-Y'),
                    ])->values(),
                    'pdm'             => $pdm ? [
                        'id'                 => $pdm->id,
                        'jumlah'             => (float) $pdm->jumlah,
                        'status'             => $pdm->status,
                        'tanggal_pencatatan' => $pdm->tanggal_pencatatan?->format('d-m-Y'),
                        'keterangan'         => $pdm->keterangan,
                        'created_by'         => $pdm->createdBy?->name,
                    ] : null,
                ];
            }
        }

        return [
            'id'            => $d->id,
            'tanggal'       => $d->tanggal?->format('d-m-Y'),
            'keterangan'    => $d->keterangan,
            'no_referensi'  => $d->no_referensi,
            'debit'         => $d->debit,
            'kredit'        => $d->kredit,
            'saldo'         => $d->saldo,
            'status_cocok'     => $d->status_cocok,
            'status_posting_2' => $d->status_posting_2 ?? 'PENDING',
            'posted_by'        => $d->postedBy?->name,
            'selisih_bank'     => $selisihBank,
            'matched_by'       => $d->matchedBy?->name,
            'can_manage_match' => RoleHelper::canManageMatchedRecord(auth()->user(), $d->matched_by),
            'kelebihan_bayar'  => $kelebihanBayar,
            'pembayaran'    => $pembayaran ? [
                'id'                 => $pembayaran->id,
                'no_referensi'       => $pembayaran->no_referensi,
                'tanggal_pembayaran' => $pembayaran->tanggal_pembayaran?->format('d-m-Y'),
                'jumlah_pembayaran'  => $pembayaran->jumlah_pembayaran,
                'metode_pembayaran'  => $pembayaran->metode_pembayaran,
                'klien'              => $invoice?->klienAr?->nama_klien,
            ] : null,
        ];
    }

    public function getKandidat(BankStatementDetail $detail): \Illuminate\Support\Collection
    {
        $lockedIds = BankStatementDetail::where('bank_statement_id', $detail->bank_statement_id)
            ->where('status_cocok', 'MATCHED')
            ->where('id', '!=', $detail->id)
            ->whereNotNull('pembayaran_ar_id')
            ->pluck('pembayaran_ar_id');

        return PembayaranAr::with(['invoice.klienAr'])
            ->where('metode_pembayaran', 'TRANSFER')
            ->whereNotIn('id', $lockedIds)
            ->orderByDesc('tanggal_pembayaran')
            ->limit(50)
            ->get()
            ->map(fn($p) => [
                'id'                 => $p->id,
                'no_referensi'       => $p->no_referensi,
                'tanggal_pembayaran' => $p->tanggal_pembayaran?->toDateString(),
                'jumlah_pembayaran'  => $p->jumlah_pembayaran,
                'metode_pembayaran'  => $p->metode_pembayaran,
                'klien'              => $p->invoice?->klienAr?->nama_klien,
                'no_invoice'         => $p->invoice?->no_invoice,
                'keterangan'         => $p->keterangan,
            ]);
    }

    public function manualMatch(BankStatementDetail $detail, int $pembayaranArId): BankStatementDetail
    {
        $alreadyUsed = BankStatementDetail::where('bank_statement_id', $detail->bank_statement_id)
            ->where('status_cocok', 'MATCHED')
            ->where('pembayaran_ar_id', $pembayaranArId)
            ->where('id', '!=', $detail->id)
            ->exists();

        abort_if($alreadyUsed, 422, 'Pembayaran ini sudah dicocokkan ke transaksi lain.');

        $detail->update([
            'status_cocok'    => 'MATCHED',
            'pembayaran_ar_id'=> $pembayaranArId,
            'matched_by'      => auth()->id(),
        ]);

        $this->refreshCounter($detail->bank_statement_id);

        return $detail->fresh()->load('pembayaranAr.invoice.klienAr', 'matchedBy');
    }

    public function unmatch(BankStatementDetail $detail): BankStatementDetail
    {
        abort_if($detail->status_cocok !== 'MATCHED', 422, 'Hanya transaksi MATCHED yang dapat dibatalkan.');

        $pembayaran = $detail->pembayaranAr;

        return DB::transaction(function () use ($detail, $pembayaran) {
            // Pembayaran yang dibuat otomatis oleh "Catat Bayar" harus ikut dibatalkan
            // agar invoice tidak tetap tercatat terbayar (mencegah dobel bayar saat
            // detail bank dicocokkan ulang). PembayaranArService::delete() sudah
            // melepas tautan detail bank, recalculate invoice, dan refresh counter.
            if ($pembayaran && $pembayaran->dibuat_dari_rekonsiliasi) {
                $this->pembayaranArService->delete($pembayaran);

                return $detail->fresh();
            }

            // Pembayaran pre-existing yang dicocokkan manual: cukup lepas tautannya,
            // pembayaran tetap dipertahankan.
            $detail->update([
                'status_cocok'    => 'UNMATCHED',
                'pembayaran_ar_id'=> null,
            ]);

            $this->refreshCounter($detail->bank_statement_id);

            return $detail->fresh();
        });
    }

    public function markDiabaikan(BankStatementDetail $detail): void
    {
        $detail->update([
            'status_cocok'    => 'DIABAIKAN',
            'pembayaran_ar_id'=> null,
        ]);

        $this->refreshCounter($detail->bank_statement_id);
    }

    public function getInvoiceB2CKlien(BankStatementDetail $detail): \Illuminate\Support\Collection
    {
        $sourceInvoice = $detail->pembayaranAr?->invoice;
        abort_if(!$sourceInvoice, 422, 'Belum ada pembayaran yang dicocokkan.');

        $sourceInvoice->loadMissing('klienAr.resto');
        $investorId = $sourceInvoice->klienAr?->resto?->investor_id;

        $query = Invoice::with('klienAr.resto.investor')
            ->whereNotIn('status', ['LUNAS'])
            ->whereHas('klienAr', fn($q) => $q->where('tipe_klien', 'RESTO'))
            ->orderByDesc('tanggal_invoice');

        if ($investorId) {
            $query->whereHas('klienAr.resto', fn($q) => $q->where('investor_id', $investorId));
        } else {
            $query->where('klien_ar_id', $sourceInvoice->klien_ar_id);
        }

        return $query->get()->map(function ($inv) {
            $subtotal        = (float) $inv->subtotal;
            $totalPembayaran = (float) $inv->total_pembayaran;
            $totalEfektif    = $subtotal > 0 ? $subtotal : (float) $inv->total_tagihan;
            $sisaEfektif     = $subtotal > 0
                ? max(0, $subtotal - $totalPembayaran)
                : (float) $inv->sisa_tagihan;

            return [
                'id'             => $inv->id,
                'no_invoice'     => $inv->no_invoice,
                'tanggal'        => $inv->tanggal_invoice?->toDateString(),
                'total_tagihan'  => $totalEfektif,
                'sisa_tagihan'   => $sisaEfektif,
                'status'         => $inv->status,
                'nama_klien'     => $inv->klienAr?->nama_klien,
                'nama_resto'     => $inv->klienAr?->resto?->nama_resto,
                'nama_investor'  => $inv->klienAr?->resto?->investor?->nama_investor,
            ];
        });
    }

    public function getInvoiceB2BKlien(BankStatementDetail $detail): \Illuminate\Support\Collection
    {
        $sourceInvoice = $detail->pembayaranAr?->invoice;
        abort_if(!$sourceInvoice, 422, 'Belum ada pembayaran yang dicocokkan.');

        return Invoice::with('klienAr')
            ->whereNotIn('status', ['LUNAS'])
            ->where('klien_ar_id', $sourceInvoice->klien_ar_id)
            ->whereHas('klienAr', fn($q) => $q->where('tipe_klien', '!=', 'RESTO'))
            ->orderByDesc('tanggal_invoice')
            ->get()
            ->map(function ($inv) {
                $subtotal        = (float) $inv->subtotal;
                $totalPembayaran = (float) $inv->total_pembayaran;
                $totalEfektif    = $subtotal > 0 ? $subtotal : (float) $inv->total_tagihan;
                $sisaEfektif     = $subtotal > 0
                    ? max(0, $subtotal - $totalPembayaran)
                    : (float) $inv->sisa_tagihan;

                return [
                    'id'                 => $inv->id,
                    'no_invoice'         => $inv->no_invoice,
                    'tanggal'            => $inv->tanggal_invoice?->toDateString(),
                    'total_tagihan'      => $totalEfektif,
                    'sisa_tagihan'       => $sisaEfektif,
                    'status'             => $inv->status,
                    'nama_klien'         => $inv->klienAr?->nama_klien,
                    'is_opening_balance' => (bool) $inv->is_opening_balance,
                ];
            });
    }

    public function applyKelebihan(
        BankStatementDetail $detail,
        int $invoiceId,
        float $jumlah,
        ?string $keterangan
    ): void {
        $pembayaran = $detail->pembayaranAr;
        abort_if(!$pembayaran, 422, 'Transaksi belum memiliki pembayaran yang dicocokkan.');

        $inv                  = $pembayaran->invoice;
        $kelebihanFromInvoice = max(0, (float) $inv->total_pembayaran - (float) $inv->total_tagihan);
        $kelebihanFromBank    = max(0, (float) $detail->kredit - (float) $pembayaran->jumlah_pembayaran);
        $total                = max($kelebihanFromInvoice, $kelebihanFromBank);
        $sudah                = (float) $pembayaran->alokasiKelebihan()->sum('jumlah_pembayaran');
        $sisa  = max(0, round($total - $sudah, 2));

        abort_if($jumlah <= 0, 422, 'Jumlah harus lebih dari 0.');
        abort_if(
            $jumlah > $sisa + 0.01,
            422,
            'Jumlah melebihi sisa kelebihan (Rp ' . number_format($sisa, 0, ',', '.') . ').'
        );

        $target = Invoice::with('klienAr.resto')->findOrFail($invoiceId);
        $inv->loadMissing('klienAr.resto');
        $sourceInvestorId = $inv->klienAr?->resto?->investor_id;
        $targetInvestorId = $target->klienAr?->resto?->investor_id;

        if ($sourceInvestorId && $targetInvestorId) {
            abort_if(
                $sourceInvestorId !== $targetInvestorId,
                422,
                'Invoice tujuan harus milik investor yang sama.'
            );
        } else {
            abort_if(
                $target->klien_ar_id !== $inv->klien_ar_id,
                422,
                'Invoice tujuan harus milik klien yang sama.'
            );
        }
        abort_if($target->status === 'LUNAS', 422, 'Invoice ini sudah LUNAS.');
        abort_if(
            $target->requiresApproval() && !$target->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, pembayaran belum dapat diproses'
        );

        $targetSubtotal    = (float) $target->subtotal;
        $targetTotalBayar  = (float) $target->pembayarans()->sum('jumlah_pembayaran');
        $targetSisaEfektif = $targetSubtotal > 0
            ? max(0, $targetSubtotal - $targetTotalBayar)
            : max(0, (float) $target->total_tagihan - $targetTotalBayar);
        abort_if(
            $jumlah > $targetSisaEfektif + 0.01,
            422,
            'Jumlah melebihi sisa tagihan invoice tujuan (Rp ' . number_format($targetSisaEfektif, 0, ',', '.') . ').'
        );

        DB::transaction(function () use ($invoiceId, $jumlah, $pembayaran, $inv, $keterangan, $target) {
            PembayaranAr::create([
                'invoice_id'              => $invoiceId,
                'tanggal_pembayaran'      => $pembayaran->tanggal_pembayaran,
                'jumlah_pembayaran'       => $jumlah,
                'metode_pembayaran'       => $pembayaran->metode_pembayaran,
                'no_referensi'            => $pembayaran->no_referensi
                                                ? $pembayaran->no_referensi . '/ALO-' . ($this->nextAlokasiSuffix($pembayaran))
                                                : null,
                'keterangan'              => $keterangan ?? 'Alokasi kelebihan dari ' . $inv->no_invoice,
                'sumber_pembayaran_ar_id' => $pembayaran->id,
                'created_by'              => auth()->id(),
            ]);

            // Update Invoice B langsung tanpa cascade ke invoice-invoice berikutnya.
            // Memanggil InvoiceService::recalculate() akan memicu cascadeCarryoverToNext()
            // yang mengurangi total_tagihan Invoice A (sumber kelebihan), sehingga $total
            // naik sebesar alokasi dan $sisa tidak pernah berkurang ke 0 (circular dependency).
            $fresh     = $target->fresh();
            $newTotal  = (float) $fresh->pembayarans()->sum('jumlah_pembayaran');
            $subtotal  = (float) $fresh->subtotal;
            $rawSisa   = max(0, (float) $fresh->total_tagihan - $newTotal);
            $isLunas   = $subtotal > 0 ? $newTotal >= $subtotal : $rawSisa <= 0;
            $newSisa   = $isLunas ? 0 : $rawSisa;
            $newStatus = match (true) {
                $isLunas      => 'LUNAS',
                $newTotal > 0 => 'SEBAGIAN',
                default       => 'TERKIRIM',
            };
            $fresh->update([
                'total_pembayaran' => $newTotal,
                'sisa_tagihan'     => $newSisa,
                'status'           => $newStatus,
                'updated_by'       => auth()->id(),
            ]);
        });
    }

    public function getInvoiceCandidatesForNewPayment(
        BankStatementDetail $detail,
        ?string $search = null,
        ?string $type = null,
        int $page = 1,
        int $perPage = 50,
        ?int $picArKaryawanId = null,
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = Invoice::with('klienAr.resto')
            ->whereNotIn('status', ['LUNAS', 'DRAFT'])
            ->where(function ($q) {
                $q->where('is_opening_balance', false)
                  ->orWhere(function ($q2) {
                      $q2->where('is_opening_balance', true)
                         ->where('approval_status', 'APPROVED');
                  });
            })
            ->when($picArKaryawanId, fn($q, $v) =>
                $q->whereHas('klienAr', fn($q2) => $q2->withTrashed()->where('karyawan_ar_id', $v))
            )
            ->orderByDesc('tanggal_invoice');

        if ($type === 'ob') {
            $query->where('is_opening_balance', true);
        } elseif ($type === 'regular') {
            $query->where('is_opening_balance', false);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('no_invoice', 'LIKE', "%{$search}%")
                  ->orWhereHas('klienAr', fn($q2) => $q2->where('nama_klien', 'LIKE', "%{$search}%"));
            });
        }

        return $query->paginate($perPage, ['*'], 'page', $page)->through(function ($inv) {
            $subtotal         = (float) $inv->subtotal;
            $totalPembayaran  = (float) $inv->total_pembayaran;
            $totalPenyesuaian = (float) $inv->total_penyesuaian;
            $sisaEfektif      = $subtotal > 0
                ? max(0, $subtotal - $totalPembayaran - $totalPenyesuaian)
                : (float) $inv->sisa_tagihan;

            return [
                'id'                 => $inv->id,
                'no_invoice'         => $inv->no_invoice,
                'tanggal'            => $inv->tanggal_invoice?->toDateString(),
                'total_tagihan'      => $subtotal > 0 ? $subtotal : (float) $inv->total_tagihan,
                'sisa_tagihan'       => $sisaEfektif,
                'status'             => $inv->status,
                'nama_klien'         => $inv->klienAr?->nama_klien,
                'nama_resto'         => $inv->klienAr?->resto?->nama_resto,
                'is_opening_balance' => (bool) $inv->is_opening_balance,
            ];
        });
    }

    public function matchWithNewPayment(
        BankStatementDetail $detail,
        Invoice $invoice,
        array $settleOriginalInvoiceIds = [],
        ?UploadedFile $buktiBayar = null,
    ): BankStatementDetail {
        abort_if($detail->status_cocok === 'MATCHED', 422, 'Transaksi ini sudah dicocokkan.');
        abort_if($detail->kredit <= 0, 422, 'Hanya transaksi kredit yang dapat dicatat pembayarannya.');

        return DB::transaction(function () use ($detail, $invoice, $settleOriginalInvoiceIds, $buktiBayar) {
            $subtotal         = (float) $invoice->subtotal;
            $totalBayar       = (float) $invoice->total_pembayaran;
            $totalPenyesuaian = (float) $invoice->total_penyesuaian;
            $sisaEfektif      = $subtotal > 0
                ? max(0, $subtotal - $totalBayar - $totalPenyesuaian)
                : max(0, (float) $invoice->total_tagihan - $totalBayar - $totalPenyesuaian);
            $jumlahBayar = min((float) $detail->kredit, $sisaEfektif);

            $paymentData = [
                'tanggal_pembayaran'          => $detail->tanggal,
                'jumlah_pembayaran'           => $jumlahBayar,
                'metode_pembayaran'           => 'TRANSFER',
                'no_referensi'                => $detail->no_referensi ?: null,
                'keterangan'                  => null,
                'dibuat_dari_rekonsiliasi'    => true,
                'settle_original_invoice_ids' => $settleOriginalInvoiceIds,
            ];

            $pembayaran = $this->pembayaranArService->create($invoice, $paymentData, $buktiBayar);

            return $this->manualMatch($detail, $pembayaran->id);
        });
    }

    private function nextAlokasiSuffix(PembayaranAr $pembayaran): int
    {
        $prefix = $pembayaran->no_referensi . '/ALO-';
        $max = $pembayaran->alokasiKelebihan()
            ->where('no_referensi', 'LIKE', $prefix . '%')
            ->get(['no_referensi'])
            ->map(function ($a) use ($prefix) {
                $n = substr($a->no_referensi ?? '', strlen($prefix));
                return is_numeric($n) ? (int) $n : 0;
            })
            ->max() ?? 0;
        return $max + 1;
    }

    private function refreshCounter(int $statementId): void
    {
        $matched   = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'MATCHED')->count();
        $unmatched = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'UNMATCHED')->count();

        BankStatement::where('id', $statementId)->update([
            'jumlah_matched'   => $matched,
            'jumlah_unmatched' => $unmatched,
        ]);
    }
}
