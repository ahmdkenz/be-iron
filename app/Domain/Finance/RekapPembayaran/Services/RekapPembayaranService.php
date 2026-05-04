<?php

namespace App\Domain\Finance\RekapPembayaran\Services;

use App\Models\PembayaranAr;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RekapPembayaranService
{
    public function getReport(array $filters = []): array
    {
        $from = isset($filters['tanggal_dari'])
            ? Carbon::parse($filters['tanggal_dari'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = isset($filters['tanggal_sampai'])
            ? Carbon::parse($filters['tanggal_sampai'])->endOfDay()
            : Carbon::now()->endOfMonth();

        $query = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->join('tb_klien_ar', 'tb_invoice.klien_ar_id', '=', 'tb_klien_ar.id')
            ->leftJoin('tb_perusahaan', 'tb_invoice.perusahaan_id', '=', 'tb_perusahaan.id')
            ->whereBetween('tb_pembayaran_ar.tanggal_pembayaran', [$from->toDateString(), $to->toDateString()])
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('tb_invoice.klien_ar_id', $v))
            ->when($filters['metode_pembayaran'] ?? null, fn($q, $v) => $q->where('tb_pembayaran_ar.metode_pembayaran', $v));

        // Rekap per metode
        $perMetode = (clone $query)
            ->select(
                'tb_pembayaran_ar.metode_pembayaran',
                DB::raw('COUNT(*) as jumlah_transaksi'),
                DB::raw('SUM(tb_pembayaran_ar.jumlah_pembayaran) as total')
            )
            ->groupBy('tb_pembayaran_ar.metode_pembayaran')
            ->get()
            ->keyBy('metode_pembayaran');

        // Rekap per tanggal
        $perTanggal = (clone $query)
            ->select(
                'tb_pembayaran_ar.tanggal_pembayaran',
                DB::raw('SUM(CASE WHEN tb_pembayaran_ar.metode_pembayaran = "TRANSFER" THEN tb_pembayaran_ar.jumlah_pembayaran ELSE 0 END) as transfer'),
                DB::raw('SUM(CASE WHEN tb_pembayaran_ar.metode_pembayaran = "CASH" THEN tb_pembayaran_ar.jumlah_pembayaran ELSE 0 END) as cash'),
                DB::raw('SUM(CASE WHEN tb_pembayaran_ar.metode_pembayaran = "GIRO" THEN tb_pembayaran_ar.jumlah_pembayaran ELSE 0 END) as giro'),
                DB::raw('SUM(tb_pembayaran_ar.jumlah_pembayaran) as total')
            )
            ->groupBy('tb_pembayaran_ar.tanggal_pembayaran')
            ->orderBy('tb_pembayaran_ar.tanggal_pembayaran', 'asc')
            ->get();

        $grandTotal   = $perTanggal->sum('total');
        $totalTransfer = (float) ($perMetode['TRANSFER']->total ?? 0);
        $totalCash     = (float) ($perMetode['CASH']->total ?? 0);
        $totalGiro     = (float) ($perMetode['GIRO']->total ?? 0);

        $summary = [
            'total'              => (float) $grandTotal,
            'transfer'           => $totalTransfer,
            'cash'               => $totalCash,
            'giro'               => $totalGiro,
            'jumlah_transaksi'   => (int) $perMetode->sum('jumlah_transaksi'),
        ];

        $rows = $perTanggal->map(fn($r) => [
            'tanggal'  => $r->tanggal_pembayaran,
            'transfer' => (float) $r->transfer,
            'cash'     => (float) $r->cash,
            'giro'     => (float) $r->giro,
            'total'    => (float) $r->total,
        ])->values()->all();

        return [
            'tanggal_dari'    => $from->toDateString(),
            'tanggal_sampai'  => $to->toDateString(),
            'summary'         => $summary,
            'rows'            => $rows,
        ];
    }
}
