<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Controllers;

use App\Domain\Finance\RekonsiliasiBankStatement\BankTemplateGenerator;
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
            'created_at'       => $s->created_at?->toDateTimeString(),
        ]);

        return $this->successResponse($data);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'bank_type' => ['required', 'in:BCA,MANDIRI,CIMB,BSI'],
            'file'      => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:10240'],
        ]);

        try {
            $statement = $this->service->upload(
                $request->file('file'),
                $request->bank_type,
                auth()->id()
            );

            return $this->successResponse([
                'id'               => $statement->id,
                'total_transaksi'  => $statement->total_transaksi,
                'jumlah_matched'   => $statement->jumlah_matched,
                'jumlah_unmatched' => $statement->jumlah_unmatched,
            ], 'File berhasil diupload dan diproses.');
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
