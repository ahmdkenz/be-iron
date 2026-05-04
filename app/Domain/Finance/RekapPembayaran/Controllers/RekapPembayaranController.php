<?php

namespace App\Domain\Finance\RekapPembayaran\Controllers;

use App\Domain\Finance\RekapPembayaran\Services\RekapPembayaranService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RekapPembayaranController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RekapPembayaranService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal_dari'      => ['nullable', 'date'],
            'tanggal_sampai'    => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'klien_ar_id'       => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'metode_pembayaran' => ['nullable', 'in:TRANSFER,CASH,GIRO'],
        ]);

        $report = $this->service->getReport(
            $request->only(['tanggal_dari', 'tanggal_sampai', 'klien_ar_id', 'metode_pembayaran'])
        );

        return $this->successResponse($report);
    }
}
