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

        $segmentTypes = $this->resolveSegmentTypes($filters['segment'] ?? null);

        $query = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->join('tb_klien_ar', 'tb_invoice.klien_ar_id', '=', 'tb_klien_ar.id')
            ->leftJoin('tb_perusahaan', 'tb_invoice.perusahaan_id', '=', 'tb_perusahaan.id')
            ->leftJoin('tb_karyawan as pic_karyawan', 'tb_klien_ar.karyawan_ar_id', '=', 'pic_karyawan.id')
            ->leftJoin('tb_bank_statement_detail as bsd', 'bsd.pembayaran_ar_id', '=', 'tb_pembayaran_ar.id')
            ->whereBetween('tb_pembayaran_ar.tanggal_pembayaran', [$from->toDateString(), $to->toDateString()])
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('tb_invoice.klien_ar_id', $v))
            ->when($filters['metode_pembayaran'] ?? null, fn($q, $v) => $q->where('tb_pembayaran_ar.metode_pembayaran', $v))
            ->when($segmentTypes, fn($q) => $q->whereIn('tb_klien_ar.tipe_klien', $segmentTypes));

        // Rekap per metode (untuk summary cards)
        $perMetode = (clone $query)
            ->select(
                'tb_pembayaran_ar.metode_pembayaran',
                DB::raw('COUNT(*) as jumlah_transaksi'),
                DB::raw('SUM(tb_pembayaran_ar.jumlah_pembayaran) as total')
            )
            ->groupBy('tb_pembayaran_ar.metode_pembayaran')
            ->get()
            ->keyBy('metode_pembayaran');

        // Data per record individual
        $perRecord = (clone $query)
            ->select(
                'tb_pembayaran_ar.tanggal_pembayaran',
                'tb_klien_ar.nama_klien',
                'tb_invoice.no_invoice',
                'tb_pembayaran_ar.no_referensi',
                'tb_pembayaran_ar.metode_pembayaran',
                'tb_pembayaran_ar.jumlah_pembayaran',
                DB::raw('COALESCE(pic_karyawan.nama_karyawan, "") as pic_ar'),
                DB::raw('IF(bsd.id IS NOT NULL, 1, 0) as is_rekon')
            )
            ->orderBy('tb_pembayaran_ar.tanggal_pembayaran', 'asc')
            ->get();

        $grandTotal    = (float) $perMetode->sum('total');
        $totalTransfer = (float) ($perMetode['TRANSFER']->total ?? 0);
        $totalCash     = (float) ($perMetode['CASH']->total ?? 0);
        $totalGiro     = (float) ($perMetode['GIRO']->total ?? 0);

        $summary = [
            'total'            => $grandTotal,
            'transfer'         => $totalTransfer,
            'cash'             => $totalCash,
            'giro'             => $totalGiro,
            'jumlah_transaksi' => (int) $perMetode->sum('jumlah_transaksi'),
        ];

        $rows = $perRecord->map(fn($r) => [
            'tanggal'     => $r->tanggal_pembayaran,
            'client'      => $r->nama_klien,
            'invoice'     => $r->no_invoice,
            'ref_payment' => $r->no_referensi,
            'metode'      => $r->metode_pembayaran,
            'nominal'     => (float) $r->jumlah_pembayaran,
            'pic_ar'      => $r->pic_ar,
            'is_rekon'    => (bool) $r->is_rekon,
        ])->values()->all();

        return [
            'tanggal_dari'    => $from->toDateString(),
            'tanggal_sampai'  => $to->toDateString(),
            'summary'         => $summary,
            'rows'            => $rows,
        ];
    }

    private function resolveSegmentTypes(?string $segment): ?array
    {
        return match(strtoupper($segment ?? '')) {
            'B2B'   => ['PT'],
            'B2C'   => ['RESTO'],
            default => null,
        };
    }
}
