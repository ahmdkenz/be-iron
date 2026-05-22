<?php

namespace App\Domain\Finance\JurnalPic\Controllers;

use App\Domain\Finance\JurnalPic\Resources\JurnalPicResource;
use App\Domain\Finance\JurnalPic\Services\JurnalPicService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JurnalPicController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly JurnalPicService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'no_referensi'        => ['nullable', 'string', 'max:100'],
            'karyawan_id'         => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'metode_pembayaran'   => ['nullable', 'in:TRANSFER,CASH,GIRO'],
            'tanggal_dari'        => ['nullable', 'date'],
            'tanggal_sampai'      => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'status_rekonsiliasi' => ['nullable', 'in:MATCHED,POSSIBLE,UNMATCHED,DIABAIKAN'],
            'per_page'            => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters           = $request->only([
            'no_referensi',
            'karyawan_id',
            'metode_pembayaran',
            'tanggal_dari',
            'tanggal_sampai',
            'status_rekonsiliasi',
            'per_page',
        ]);
        $filters['_user']  = auth()->user()->load('karyawan');

        $paginator = $this->service->getJurnal($filters);

        return $this->paginatedResponse(
            $paginator->through(fn($p) => new JurnalPicResource($p))
        );
    }
}
