<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Controllers;

use App\Domain\Finance\RekonsiliasiBankStatement\BankTemplateGenerator;
use App\Domain\Finance\RekonsiliasiBankStatement\Exceptions\DuplicateStatementException;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Http\Controllers\Controller;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
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
        $statements = BankStatement::with('uploader')
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

        return $this->successResponse($data);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'bank_type' => ['required', 'in:BCA,MANDIRI,CIMB,BSI'],
            'file'      => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:10240'],
            'force'     => ['sometimes', 'boolean'],
        ]);

        try {
            $statement = $this->service->upload(
                $request->file('file'),
                $request->bank_type,
                auth()->id(),
                $request->boolean('force', false),
            );

            return $this->successResponse([
                'id'               => $statement->id,
                'total_transaksi'  => $statement->total_transaksi,
                'jumlah_matched'   => $statement->jumlah_matched,
                'jumlah_unmatched' => $statement->jumlah_unmatched,
            ], 'File berhasil diupload dan diproses.');
        } catch (DuplicateStatementException $e) {
            $s = $e->getStatement();

            return $this->errorResponse('Rekening koran sudah diupload untuk periode ini.', 409, [
                'existing' => [
                    'id'               => $s->id,
                    'nama_file'        => $s->nama_file,
                    'periode_awal'     => $s->periode_awal?->toDateString(),
                    'periode_akhir'    => $s->periode_akhir?->toDateString(),
                    'total_transaksi'  => $s->total_transaksi,
                    'jumlah_matched'   => $s->jumlah_matched,
                    'jumlah_unmatched' => $s->jumlah_unmatched,
                    'uploaded_by'      => $s->uploader?->name,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function show(BankStatement $bankStatement): JsonResponse
    {
        return $this->successResponse(
            $this->service->getDetail($bankStatement->id)
        );
    }

    public function destroy(BankStatement $bankStatement): JsonResponse
    {
        $bankStatement->details()->delete();
        $bankStatement->delete();

        return $this->successResponse(null, 'Data berhasil dihapus.');
    }

    public function markDiabaikan(BankStatementDetail $detail): JsonResponse
    {
        $this->service->markDiabaikan($detail);

        return $this->successResponse(null, 'Transaksi ditandai diabaikan.');
    }

    public function kandidat(BankStatementDetail $detail): JsonResponse
    {
        $list = $this->service->getKandidat($detail);

        return $this->successResponse($list);
    }

    public function matchDetail(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $request->validate([
            'pembayaran_ar_id' => ['required', 'integer', 'exists:tb_pembayaran_ar,id'],
        ]);

        try {
            $updated = $this->service->manualMatch($detail, $request->integer('pembayaran_ar_id'));

            $updated->load([
                'pembayaranAr.alokasiKelebihan.invoice.klienAr',
                'pembayaranAr.alokasiKelebihan.createdBy',
            ]);

            $pembayaran     = $updated->pembayaranAr;
            $invoice        = $pembayaran?->invoice;
            $kelebihanBayar = null;
            if ($invoice) {
                $total = max(0, round((float) $invoice->total_pembayaran - (float) $invoice->total_tagihan, 2));
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
        try {
            $this->service->unmatch($detail);

            return $this->successResponse(null, 'Cocok transaksi berhasil dibatalkan.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function invoiceB2C(BankStatementDetail $detail): JsonResponse
    {
        try {
            return $this->successResponse($this->service->getInvoiceB2CKlien($detail));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }
    }

    public function applyKelebihanBayar(Request $request, BankStatementDetail $detail): JsonResponse
    {
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
}
