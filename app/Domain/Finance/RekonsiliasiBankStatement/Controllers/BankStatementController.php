<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Controllers;

use App\Domain\Finance\RekonsiliasiBankStatement\BankTemplateGenerator;
use App\Domain\Finance\RekonsiliasiBankStatement\Jobs\ImportBankStatementJob;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Http\Controllers\Controller;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\BankStatementImportBatch;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PendapatanDiMuka;
use App\Models\TagihanAp;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BankStatementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BankStatementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $statements = BankStatement::with('uploader.karyawan')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        $data = $statements->through(fn($s) => [
            'id'               => $s->id,
            'bank_type'        => $s->bank_type,
            'nama_file'        => $s->nama_file,
            'periode_awal'     => $s->periode_awal?->toDateString(),
            'periode_akhir'    => $s->periode_akhir?->toDateString(),
            'total_transaksi'  => $s->total_transaksi,
            'total_kredit'     => $s->total_kredit,
            'jumlah_matched'   => $s->jumlah_matched,
            'jumlah_unmatched' => $s->jumlah_unmatched,
            'uploaded_by'      => $s->uploader?->name,
            'created_at'       => $s->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ]);

        // Kontrak flat {data:[...], meta:{...}} (bukan paginator bersarang) —
        // dibutuhkan oleh composable FE useLoadMore (lihat paginatedResponse()).
        return $this->paginatedResponse($data);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'bank_type' => ['nullable', 'in:BCA,MANDIRI,CIMB,BSI,GENERAL'],
            'file'      => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        BankStatementImportBatch::failStale();

        $path = $request->file('file')->store('bank-statement-imports');

        $batch = BankStatementImportBatch::create([
            'user_id'           => auth()->id(),
            'bank_type'         => $request->input('bank_type', 'GENERAL'),
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'file_path'         => $path,
            'status'            => 'queued',
            'phase'             => 'queued',
        ]);

        ImportBankStatementJob::dispatch($batch->id);

        return $this->successResponse([
            'batch_id' => $batch->id,
            'status'   => $batch->status,
        ], 'File diterima. Import sedang diproses di latar belakang.', 202);
    }

    public function importStatus(string $batch): JsonResponse
    {
        BankStatementImportBatch::failStale();

        $b = BankStatementImportBatch::find($batch);
        if (!$b) {
            return $this->notFoundResponse('Batch import tidak ditemukan.');
        }

        $this->authorizeBatchAccess($b);

        return $this->successResponse([
            'batch_id'          => $b->id,
            'status'            => $b->status,
            'phase'             => $b->phase,
            'message'           => $b->message,
            'periode_awal'      => $b->periode_awal?->toDateString(),
            'periode_akhir'     => $b->periode_akhir?->toDateString(),
            'total_rows'        => $b->total_rows,
            'processed_rows'    => $b->processed_rows,
            'inserted_rows'     => $b->inserted_rows,
            'matched_rows'      => $b->matched_rows,
            'unmatched_rows'    => $b->unmatched_rows,
            'ignored_rows'      => $b->ignored_rows,
            'error_rows'        => $b->error_rows,
            'total_kredit'      => $b->total_kredit,
            'bank_statement_id' => $b->bank_statement_id,
            'overlaps'          => $b->overlaps ?? [],
            'errors'            => $b->errors ?? [],
        ]);
    }

    public function confirmReplace(string $batch): JsonResponse
    {
        $b = BankStatementImportBatch::find($batch);
        if (!$b) {
            return $this->notFoundResponse('Batch import tidak ditemukan.');
        }

        $this->authorizeBatchAccess($b);

        if ($b->status !== 'needs_confirmation') {
            return $this->errorResponse('Batch ini tidak sedang menunggu konfirmasi.', 422);
        }

        $b->update([
            'force_replace' => true,
            'status'        => 'queued',
            'phase'         => 'queued',
            'message'       => null,
        ]);

        ImportBankStatementJob::dispatch($b->id);

        return $this->successResponse([
            'batch_id' => $b->id,
            'status'   => $b->status,
        ], 'Import dilanjutkan.');
    }

    private function authorizeBatchAccess(BankStatementImportBatch $batch): void
    {
        $user = auth()->user();
        abort_unless(
            $batch->user_id === $user->id || RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR']),
            403,
            'Anda tidak memiliki akses ke batch import ini.'
        );
    }

    public function show(BankStatement $bankStatement): JsonResponse
    {
        return $this->successResponse(
            $this->service->getDetail($bankStatement->id)
        );
    }

    public function paginatedDetails(Request $request, BankStatement $bankStatement): JsonResponse
    {
        $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status'   => ['nullable', 'string', 'in:SEMUA,MATCHED,UNMATCHED,POSSIBLE,DIABAIKAN'],
        ]);

        $result = $this->service->paginateDetails(
            $bankStatement,
            $request->string('status') ?: null,
            $request->integer('page', 1),
            $request->integer('per_page', 25),
        );

        // Kontrak flat {data:[...], meta:{...}} (bukan {header, rows, meta}
        // bersarang) — dibutuhkan oleh composable FE useLoadMore. Info header
        // laporan (jumlah_matched dkk) dipisah ke endpoint ringan header().
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $result['rows'],
            'meta'    => $result['meta'],
        ]);
    }

    // Info ringkasan rekening koran (header) terpisah dari daftar baris ber-
    // paginasi, supaya FE bisa muat header sekali saja tanpa ikut ter-refetch
    // di setiap "Muat lagi"/reset filter pada useLoadMore.
    public function header(BankStatement $bankStatement): JsonResponse
    {
        return $this->successResponse($this->service->getHeader($bankStatement));
    }

    public function destroy(BankStatement $bankStatement): JsonResponse
    {
        $bankStatement->details()->delete();
        $bankStatement->delete();

        return $this->successResponse(null, 'Data berhasil dihapus.');
    }

    public function markDiabaikan(BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeRowScope($detail);

        $this->service->markDiabaikan($detail);

        return $this->successResponse(null, 'Transaksi ditandai diabaikan.');
    }

    public function kandidat(BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeArFlow();

        $list = $this->service->getKandidat($detail);

        return $this->successResponse($list);
    }

    public function matchDetail(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeArFlow();

        $request->validate([
            'pembayaran_ar_id' => ['required', 'integer', 'exists:tb_pembayaran_ar,id'],
        ]);

        try {
            $updated = $this->service->manualMatch($detail, $request->integer('pembayaran_ar_id'));

            $updated->load([
                'pembayaranAr.alokasiKelebihan.invoice.klienAr',
                'pembayaranAr.alokasiKelebihan.createdBy.karyawan',
                'matchedBy.karyawan',
            ]);

            $pembayaran     = $updated->pembayaranAr;
            $invoice        = $pembayaran?->invoice;
            $kelebihanBayar = null;
            if ($invoice) {
                $kelebihanFromInvoice = max(0, round((float) $invoice->total_pembayaran - (float) $invoice->total_tagihan, 2));
                $kelebihanFromBank    = max(0, round($updated->kredit - (float) $pembayaran->jumlah_pembayaran, 2));
                $total                = max($kelebihanFromInvoice, $kelebihanFromBank);
                if ($total > 0) {
                    $dialokasi      = (float) $pembayaran->alokasiKelebihan->sum('jumlah_pembayaran');
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
                            'tanggal'    => $p->tanggal_pembayaran?->toDateString(),
                        ])->values(),
                    ];
                }
            }

            return $this->successResponse([
                'id'              => $updated->id,
                'status_cocok'    => $updated->status_cocok,
                'matched_by'      => $updated->matchedBy?->name,
                'selisih_bank'    => $pembayaran
                    ? round($updated->kredit - (float) $pembayaran->jumlah_pembayaran, 2)
                    : null,
                'pembayaran'      => $pembayaran ? [
                    'id'                 => $pembayaran->id,
                    'no_referensi'       => $pembayaran->no_referensi,
                    'tanggal_pembayaran' => $pembayaran->tanggal_pembayaran?->toDateString(),
                    'jumlah_pembayaran'  => $pembayaran->jumlah_pembayaran,
                    'metode_pembayaran'  => $pembayaran->metode_pembayaran,
                    'klien'              => $invoice?->klienAr?->nama_klien,
                ] : null,
                'kelebihan_bayar' => $kelebihanBayar,
            ], 'Transaksi berhasil dicocokkan.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function unmatchDetail(BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeManageMatch($detail);
        $this->authorizeRowScope($detail);

        try {
            $this->service->unmatch($detail);

            return $this->successResponse(null, 'Cocok transaksi berhasil dibatalkan.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function invoiceB2C(BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeArFlow();

        try {
            return $this->successResponse($this->service->getInvoiceB2CKlien($detail));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function invoiceB2B(BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeArFlow();

        try {
            return $this->successResponse($this->service->getInvoiceB2BKlien($detail));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function applyKelebihanBayar(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeManageMatch($detail);
        $this->authorizeArFlow();

        $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:tb_invoice,id'],
            'jumlah'     => ['required', 'numeric', 'min:0.01'],
            'keterangan' => ['nullable', 'string'],
        ]);

        try {
            $this->service->applyKelebihan(
                $detail,
                $request->integer('invoice_id'),
                (float) $request->jumlah,
                $request->keterangan,
            );

            return $this->successResponse(null, 'Kelebihan berhasil dialokasikan.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function invoiceCandidates(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeArFlow();

        $request->validate([
            'search'   => ['nullable', 'string', 'max:100'],
            'type'     => ['nullable', 'string', 'in:ob,regular'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $list = $this->service->getInvoiceCandidatesForNewPayment(
            $detail,
            $request->string('search') ?: null,
            $request->string('type') ?: null,
            $request->integer('page', 1),
            $request->integer('per_page', 50),
            RoleHelper::picArKaryawanIdFor(auth()->user()),
        );

        return $this->successResponse($list);
    }

    public function tagihanApCandidates(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeApFlow();

        $request->validate([
            'search'       => ['nullable', 'string', 'max:100'],
            'vendor_ap_id' => ['nullable', 'integer', 'exists:tb_vendor_ap,id'],
            'page'         => ['nullable', 'integer', 'min:1'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user         = auth()->user()->loadMissing('karyawan');
        $perusahaanId = (!RoleHelper::hasGlobalApAccess($user) && $user->karyawan)
            ? $user->karyawan->perusahaan_id
            : null;

        $list = $this->service->getTagihanApCandidates(
            $detail,
            $request->string('search') ?: null,
            $request->integer('vendor_ap_id') ?: null,
            $request->integer('page', 1),
            $request->integer('per_page', 50),
            RoleHelper::picApKaryawanIdFor($user),
            $perusahaanId,
        );

        return $this->successResponse($list);
    }

    public function catatBayar(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeArFlow();

        $request->validate([
            'invoice_id'                    => ['required', 'integer', 'exists:tb_invoice,id'],
            'settle_original_invoice_ids'   => ['nullable', 'array'],
            'settle_original_invoice_ids.*' => ['integer', 'exists:tb_invoice,id'],
            'bukti_pembayaran'              => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        try {
            $invoice = Invoice::findOrFail($request->integer('invoice_id'));
            $this->authorizeInvoiceOwnership($invoice);
            $updated = $this->service->matchWithNewPayment(
                $detail,
                $invoice,
                $request->input('settle_original_invoice_ids', []),
                $request->file('bukti_pembayaran'),
            );

            $updated->load([
                'pembayaranAr.alokasiKelebihan.invoice.klienAr',
                'pembayaranAr.alokasiKelebihan.createdBy.karyawan',
                'matchedBy.karyawan',
            ]);

            $pembayaran     = $updated->pembayaranAr;
            $inv            = $pembayaran?->invoice;
            $kelebihanBayar = null;
            if ($inv) {
                $kelebihanFromInvoice = max(0, round((float) $inv->total_pembayaran - (float) $inv->total_tagihan, 2));
                $kelebihanFromBank    = max(0, round($updated->kredit - (float) $pembayaran->jumlah_pembayaran, 2));
                $total                = max($kelebihanFromInvoice, $kelebihanFromBank);
                if ($total > 0) {
                    $dialokasi      = (float) $pembayaran->alokasiKelebihan->sum('jumlah_pembayaran');
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
                            'tanggal'    => $p->tanggal_pembayaran?->toDateString(),
                        ])->values(),
                    ];
                }
            }

            return $this->successResponse([
                'id'              => $updated->id,
                'status_cocok'    => $updated->status_cocok,
                'matched_by'      => $updated->matchedBy?->name,
                'selisih_bank'    => $pembayaran
                    ? round($updated->kredit - (float) $pembayaran->jumlah_pembayaran, 2)
                    : null,
                'pembayaran'      => $pembayaran ? [
                    'id'                 => $pembayaran->id,
                    'no_referensi'       => $pembayaran->no_referensi,
                    'tanggal_pembayaran' => $pembayaran->tanggal_pembayaran?->toDateString(),
                    'jumlah_pembayaran'  => $pembayaran->jumlah_pembayaran,
                    'metode_pembayaran'  => $pembayaran->metode_pembayaran,
                    'klien'              => $inv?->klienAr?->nama_klien,
                ] : null,
                'kelebihan_bayar' => $kelebihanBayar,
            ], 'Pembayaran berhasil dicatat dan dicocokkan.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function catatPdm(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeArFlow();

        $request->validate([
            'klien_ar_id'      => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'keterangan'       => ['nullable', 'string', 'max:500'],
            'bukti_pembayaran' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        try {
            $klienAr = KlienAr::findOrFail($request->integer('klien_ar_id'));
            $this->authorizeKlienArOwnership($klienAr);

            $updated = $this->service->matchAsPdm(
                $detail,
                $klienAr,
                $request->input('keterangan'),
                $request->file('bukti_pembayaran'),
            );

            $pdm = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $updated->pembayaran_ar_id)->first();

            return $this->successResponse([
                'id'           => $updated->id,
                'status_cocok' => $updated->status_cocok,
                'matched_by'   => auth()->user()?->name,
                'pdm'          => $pdm ? [
                    'id'                 => $pdm->id,
                    'jumlah'             => (float) $pdm->jumlah,
                    'status'             => $pdm->status,
                    'tanggal_pencatatan' => $pdm->tanggal_pencatatan?->toDateString(),
                    'klien'              => $klienAr->nama_klien,
                ] : null,
            ], 'Transaksi berhasil dicatat sebagai Pendapatan di Muka.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function catatVoucherAp(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $this->authorizeApFlow();

        $request->validate([
            'kategori_voucher'         => ['required', 'in:BB,NBB'],
            'keterangan'               => ['nullable', 'string', 'max:500'],
            'bukti_pembayaran'         => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'alokasi'                  => ['required', 'array', 'min:1'],
            'alokasi.*.tagihan_ap_id'  => ['required', 'integer', 'exists:tb_tagihan_ap,id'],
            'alokasi.*.jumlah'         => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $alokasi    = $request->input('alokasi');
            $tagihanIds = collect($alokasi)->pluck('tagihan_ap_id')->unique();
            $this->authorizeTagihanApScope($tagihanIds);

            $updated = $this->service->matchWithNewVoucherAp(
                $detail,
                $alokasi,
                $request->input('kategori_voucher'),
                $request->input('keterangan'),
                $request->file('bukti_pembayaran'),
            );

            $updated->load(['pembayaranAp.items.tagihanAp.vendorAp', 'matchedBy.karyawan']);
            $voucher = $updated->pembayaranAp;

            return $this->successResponse([
                'id'           => $updated->id,
                'status_cocok' => $updated->status_cocok,
                'matched_by'   => $updated->matchedBy?->name,
                'selisih_bank' => $voucher
                    ? round($updated->debit - (float) $voucher->jumlah_pembayaran, 2)
                    : null,
                'pembayaran'   => $voucher ? [
                    'id'                 => $voucher->id,
                    'no_referensi'       => $voucher->no_referensi,
                    'tanggal_pembayaran' => $voucher->tanggal_pembayaran?->toDateString(),
                    'jumlah_pembayaran'  => $voucher->jumlah_pembayaran,
                    'metode_pembayaran'  => $voucher->metode_pembayaran,
                    'vendor'             => $voucher->items->pluck('tagihanAp.vendorAp.nama_vendor')->filter()->unique()->values()->join(', '),
                    'jumlah_tagihan'     => $voucher->items->count(),
                ] : null,
            ], 'Payment Voucher AP berhasil dicatat dan dicocokkan.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function downloadTemplate(string $bankType): BinaryFileResponse|JsonResponse
    {
        $bankType = strtoupper($bankType);

        if (!in_array($bankType, BankTemplateGenerator::SUPPORTED)) {
            return $this->errorResponse(
                'Bank tidak didukung. Bank yang tersedia: ' . implode(', ', BankTemplateGenerator::SUPPORTED),
                422
            );
        }

        try {
            return (new BankTemplateGenerator())->generate($bankType);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    private function authorizeInvoiceOwnership(Invoice $invoice): void
    {
        $karyawanId = RoleHelper::picArKaryawanIdFor(auth()->user());
        if ($karyawanId === null) {
            return; // Admin/Manager/Supervisor: tidak dibatasi
        }

        $klienKaryawanArId = $invoice->klienAr()->withTrashed()->value('karyawan_ar_id');

        abort_unless(
            $klienKaryawanArId !== null && (int) $klienKaryawanArId === $karyawanId,
            403,
            'Anda tidak memiliki akses untuk memproses invoice klien ini.'
        );
    }

    private function authorizeKlienArOwnership(KlienAr $klienAr): void
    {
        $karyawanId = RoleHelper::picArKaryawanIdFor(auth()->user());
        if ($karyawanId === null) {
            return; // Admin/Manager/Supervisor: tidak dibatasi
        }

        abort_unless(
            (int) $klienAr->karyawan_ar_id === $karyawanId,
            403,
            'Anda tidak memiliki akses untuk mencatat Pendapatan di Muka klien ini.'
        );
    }

    private function authorizeManageMatch(BankStatementDetail $detail): void
    {
        abort_unless(
            RoleHelper::canManageMatchedRecord(auth()->user(), $detail->matched_by),
            403,
            'Hanya pengguna yang mencocokkan transaksi ini (atau Admin/Manager/Supervisor) yang dapat melakukan aksi ini.'
        );
    }

    private function authorizeTagihanApScope(\Illuminate\Support\Collection $tagihanIds): void
    {
        $user = auth()->user()->loadMissing('karyawan');

        if (!RoleHelper::hasGlobalApAccess($user) && $user->karyawan) {
            $luarScope = TagihanAp::whereIn('id', $tagihanIds)
                ->where('perusahaan_id', '!=', $user->karyawan->perusahaan_id)
                ->exists();

            abort_if($luarScope, 403, 'Anda tidak memiliki akses untuk mencatat pembayaran tagihan ini.');
        }
    }

    // AP murni (bukan Admin/Manager/Supervisor) tidak boleh menyentuh alur
    // pencocokan Invoice/PDM AR — itu bukan wewenangnya, meski route berbagi
    // grup middleware role:...|AR|AP yang sama dengan endpoint AP.
    private function authorizeArFlow(): void
    {
        abort_if(
            RoleHelper::isApStaff(auth()->user()),
            403,
            'Role AP tidak memiliki akses ke pencocokan transaksi AR.'
        );
    }

    // AR murni (bukan Admin/Manager/Supervisor) tidak boleh menyentuh alur
    // pembuatan Payment Voucher AP.
    private function authorizeApFlow(): void
    {
        abort_if(
            RoleHelper::isArStaff(auth()->user()),
            403,
            'Role AR tidak memiliki akses ke pencocokan transaksi AP.'
        );
    }

    // Batasi Abaikan/Unmatch per tipe baris untuk PIC AR/AP murni: AR-only cuma
    // baris kredit, AP-only cuma baris debit. Admin/Manager/Supervisor bebas.
    private function authorizeRowScope(BankStatementDetail $detail): void
    {
        $user = auth()->user();

        if (RoleHelper::isApStaff($user) && (float) $detail->kredit > 0) {
            abort(403, 'Role AP tidak memiliki akses ke transaksi kredit (AR).');
        }

        if (RoleHelper::isArStaff($user) && (float) $detail->debit > 0) {
            abort(403, 'Role AR tidak memiliki akses ke transaksi debit (AP).');
        }
    }
}
