<?php

namespace App\Domain\Finance\KinerjaAr\Services;

use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\KlienAr;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KinerjaArService
{
    public function getReport(array $filters = []): array
    {
        $from = isset($filters['periode_awal'])
            ? Carbon::parse($filters['periode_awal'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = isset($filters['periode_akhir'])
            ? Carbon::parse($filters['periode_akhir'])->endOfDay()
            : Carbon::now()->endOfMonth();

        // Agregasi invoice per AR officer (via klien_ar.karyawan_ar_id)
        $invoiceStats = Invoice::query()
            ->join('tb_klien_ar', 'tb_invoice.klien_ar_id', '=', 'tb_klien_ar.id')
            ->select(
                'tb_klien_ar.karyawan_ar_id',
                DB::raw('COUNT(DISTINCT tb_invoice.klien_ar_id) as jumlah_klien'),
                DB::raw('COUNT(tb_invoice.id) as jumlah_invoice'),
                DB::raw('SUM(tb_invoice.total_tagihan) as total_tagihan'),
                DB::raw('SUM(tb_invoice.total_pembayaran) as total_terkumpul'),
                DB::raw('SUM(tb_invoice.sisa_tagihan) as total_sisa')
            )
            ->whereNotNull('tb_klien_ar.karyawan_ar_id')
            ->whereBetween('tb_invoice.tanggal_invoice', [$from->toDateString(), $to->toDateString()])
            ->where(fn($q) => $q
                ->where('tb_invoice.is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('tb_invoice.is_opening_balance', true)->where('tb_invoice.approval_status', 'APPROVED'))
            )
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->where('tb_klien_ar.karyawan_ar_id', $v))
            ->groupBy('tb_klien_ar.karyawan_ar_id')
            ->get()
            ->keyBy('karyawan_ar_id');

        if ($invoiceStats->isEmpty()) {
            return [
                'periode_awal'  => $from->toDateString(),
                'periode_akhir' => $to->toDateString(),
                'summary'       => ['total_tagihan' => 0, 'total_terkumpul' => 0, 'total_sisa' => 0, 'collection_rate' => 0],
                'rows'          => [],
            ];
        }

        $karyawanIds = $invoiceStats->keys();
        $karyawanMap = Karyawan::with('perusahaan')
            ->whereIn('id', $karyawanIds)
            ->get()
            ->keyBy('id');

        $rows = $karyawanIds->map(function ($karyawanId) use ($invoiceStats, $karyawanMap) {
            $stat     = $invoiceStats[$karyawanId];
            $karyawan = $karyawanMap->get($karyawanId);

            $totalTagihan   = (float) $stat->total_tagihan;
            $totalTerkumpul = (float) $stat->total_terkumpul;
            $totalSisa      = (float) $stat->total_sisa;
            $collectionRate = $totalTagihan > 0
                ? round(($totalTerkumpul / $totalTagihan) * 100, 2)
                : 0;

            return [
                'karyawan_id'     => $karyawanId,
                'nama_karyawan'   => $karyawan?->nama_karyawan ?? 'Tidak Diketahui',
                'perusahaan'      => $karyawan?->perusahaan?->nama_singkatan_perusahaan,
                'jumlah_klien'    => (int) $stat->jumlah_klien,
                'jumlah_invoice'  => (int) $stat->jumlah_invoice,
                'total_tagihan'   => $totalTagihan,
                'total_terkumpul' => $totalTerkumpul,
                'total_sisa'      => $totalSisa,
                'collection_rate' => $collectionRate,
            ];
        })->sortByDesc('total_tagihan')->values()->all();

        $grandTagihan   = array_sum(array_column($rows, 'total_tagihan'));
        $grandTerkumpul = array_sum(array_column($rows, 'total_terkumpul'));
        $grandSisa      = array_sum(array_column($rows, 'total_sisa'));

        $summary = [
            'total_tagihan'   => $grandTagihan,
            'total_terkumpul' => $grandTerkumpul,
            'total_sisa'      => $grandSisa,
            'collection_rate' => $grandTagihan > 0
                ? round(($grandTerkumpul / $grandTagihan) * 100, 2)
                : 0,
        ];

        return [
            'periode_awal'  => $from->toDateString(),
            'periode_akhir' => $to->toDateString(),
            'summary'       => $summary,
            'rows'          => $rows,
        ];
    }
}
