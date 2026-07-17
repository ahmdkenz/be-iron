<?php

namespace App\Domain\Finance\Dashboard\Services;

use App\Models\PembayaranAp;
use App\Models\TagihanAp;
use App\Models\User;
use App\Support\Helpers\ApFilterScope;
use Carbon\Carbon;

class DashboardApService
{
    public function getSummary(User $user): array
    {
        $filters = [];
        ApFilterScope::apply($filters, $user);

        $base     = $this->scopedQuery($filters);
        $approved = (clone $base)->where('approval_status', 'APPROVED');
        $today    = Carbon::today();

        $totalOutstanding      = (float) (clone $approved)->where('sisa_tagihan', '>', 0)->sum('sisa_tagihan');
        $outstandingLastMonth  = $this->outstandingAsOf($filters, $today->copy()->startOfMonth()->subDay());
        $outstandingTrendPct   = $this->percentChange($totalOutstanding, $outstandingLastMonth);

        $sevenDays = $today->copy()->addDays(7);
        $jatuhTempoQuery = (clone $approved)
            ->where('sisa_tagihan', '>', 0)
            ->whereBetween('tanggal_jatuh_tempo', [$today->toDateString(), $sevenDays->toDateString()]);
        $jatuhTempo7      = (float) (clone $jatuhTempoQuery)->sum('sisa_tagihan');
        $jatuhTempo7Count = (clone $jatuhTempoQuery)->count();

        $overdueRows = (clone $approved)
            ->where('sisa_tagihan', '>', 0)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->get(['tanggal_jatuh_tempo', 'sisa_tagihan']);

        $buckets = ['current' => 0.0, '1-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
        foreach ($overdueRows as $row) {
            $days = $today->diffInDays(Carbon::parse($row->tanggal_jatuh_tempo), false) * -1;
            $key  = match (true) {
                $days <= 0  => 'current',
                $days <= 30 => '1-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default     => '90+',
            };
            $buckets[$key] += (float) $row->sisa_tagihan;
        }
        $bucketsTotal = array_sum($buckets);
        $bucketsPct   = array_map(
            fn($v) => $bucketsTotal > 0 ? round($v / $bucketsTotal * 100, 1) : 0.0,
            $buckets
        );

        $topVendorsRaw = (clone $approved)
            ->where('sisa_tagihan', '>', 0)
            ->selectRaw('vendor_ap_id, SUM(sisa_tagihan) as total_outstanding')
            ->with('vendorAp:id,kode_vendor,nama_vendor')
            ->groupBy('vendor_ap_id')
            ->orderByDesc('total_outstanding')
            ->limit(5)
            ->get();
        $topVendors = $topVendorsRaw->map(fn($row) => [
            'vendor_ap_id'      => $row->vendor_ap_id,
            'nama_vendor'       => $row->vendorAp?->nama_vendor,
            'kode_vendor'       => $row->vendorAp?->kode_vendor,
            'total_outstanding' => (float) $row->total_outstanding,
            'percentage'        => $totalOutstanding > 0 ? round((float) $row->total_outstanding / $totalOutstanding * 100, 1) : 0.0,
        ]);

        $dueSoon = (clone $approved)
            ->where('sisa_tagihan', '>', 0)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->with('vendorAp:id,kode_vendor,nama_vendor')
            ->orderBy('tanggal_jatuh_tempo')
            ->limit(6)
            ->get()
            ->map(fn($t) => [
                'id'                  => $t->id,
                'no_tagihan'          => $t->no_tagihan,
                'nama_vendor'         => $t->vendorAp?->nama_vendor,
                'tanggal_jatuh_tempo' => $t->tanggal_jatuh_tempo?->toDateString(),
                'hari_lagi'           => $today->diffInDays(Carbon::parse($t->tanggal_jatuh_tempo), false),
                'total_tagihan'       => (float) $t->total_tagihan,
                'sisa_tagihan'        => (float) $t->sisa_tagihan,
            ]);

        $bulanLalu = $today->copy()->subMonthNoOverflow();

        $bulanIni = (float) PembayaranAp::whereHas('tagihanAp', fn($q) => $this->applyScopeToTagihan($q, $filters))
            ->whereMonth('tanggal_pembayaran', $today->month)
            ->whereYear('tanggal_pembayaran', $today->year)
            ->sum('jumlah_pembayaran');

        $bulanLaluTotal = (float) PembayaranAp::whereHas('tagihanAp', fn($q) => $this->applyScopeToTagihan($q, $filters))
            ->whereMonth('tanggal_pembayaran', $bulanLalu->month)
            ->whereYear('tanggal_pembayaran', $bulanLalu->year)
            ->sum('jumlah_pembayaran');

        $pembayaranTrendPct = $this->percentChange($bulanIni, $bulanLaluTotal);

        $trend = $this->buildSixMonthTrend($filters, $today);

        $statusBreakdown = $this->buildStatusBreakdown($base);

        return [
            'total_outstanding'      => $totalOutstanding,
            'total_outstanding_trend_pct' => $outstandingTrendPct,
            'jatuh_tempo_7_hari'     => $jatuhTempo7,
            'jatuh_tempo_7_hari_count' => $jatuhTempo7Count,
            'pembayaran_bulan_ini'   => $bulanIni,
            'pembayaran_bulan_lalu'  => $bulanLaluTotal,
            'pembayaran_trend_pct'   => $pembayaranTrendPct,
            'overdue_buckets'        => $buckets,
            'overdue_buckets_pct'    => $bucketsPct,
            'top_vendors'            => $topVendors,
            'due_soon'               => $dueSoon,
            'trend'                  => $trend,
            'status_breakdown'       => $statusBreakdown,
        ];
    }

    private function outstandingAsOf(array $filters, Carbon $asOf): float
    {
        $tagihanTotal = (clone $this->scopedQuery($filters))
            ->where('approval_status', 'APPROVED')
            ->where('tanggal_tagihan', '<=', $asOf->toDateString())
            ->sum('total_tagihan');

        $dibayarSampai = PembayaranAp::whereHas('tagihanAp', fn($q) => $this->applyScopeToTagihan($q, $filters)
            ->where('approval_status', 'APPROVED')
            ->where('tanggal_tagihan', '<=', $asOf->toDateString()))
            ->where('tanggal_pembayaran', '<=', $asOf->toDateString())
            ->sum('jumlah_pembayaran');

        return max(0.0, (float) $tagihanTotal - (float) $dibayarSampai);
    }

    private function percentChange(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    private function buildSixMonthTrend(array $filters, Carbon $today): array
    {
        $labels = [];
        $paymentTotals = [];
        $invoiceCounts = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $today->copy()->subMonthsNoOverflow($i);
            $labels[] = $month->translatedFormat('M \'y');

            $paymentTotals[] = (float) PembayaranAp::whereHas('tagihanAp', fn($q) => $this->applyScopeToTagihan($q, $filters))
                ->whereMonth('tanggal_pembayaran', $month->month)
                ->whereYear('tanggal_pembayaran', $month->year)
                ->sum('jumlah_pembayaran');

            $invoiceCounts[] = (clone $this->scopedQuery($filters))
                ->whereMonth('tanggal_tagihan', $month->month)
                ->whereYear('tanggal_tagihan', $month->year)
                ->count();
        }

        return [
            'labels'         => $labels,
            'payment_totals' => $paymentTotals,
            'invoice_counts' => $invoiceCounts,
        ];
    }

    private function buildStatusBreakdown($base): array
    {
        $rows = (clone $base)
            ->where('approval_status', 'APPROVED')
            ->selectRaw('status, SUM(total_tagihan) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'lunas'       => (float) ($rows['LUNAS'] ?? 0),
            'sebagian'    => (float) ($rows['SEBAGIAN'] ?? 0),
            'belum_lunas' => (float) ($rows['DITERIMA'] ?? 0),
        ];
    }

    private function scopedQuery(array $filters)
    {
        return $this->applyScopeToTagihan(TagihanAp::query(), $filters);
    }

    private function applyScopeToTagihan($query, array $filters)
    {
        return $query
            ->where('is_opening_balance', false)
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) =>
                $q->whereHas('vendorAp', fn($q) => $q->where('karyawan_ap_id', $v))
            );
    }
}
