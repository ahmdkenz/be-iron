<?php

namespace App\Domain\Finance\Dashboard\Services;

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

        $base = $this->scopedQuery($filters);

        $totalOutstanding = (clone $base)->where('sisa_tagihan', '>', 0)->sum('sisa_tagihan');

        $today       = Carbon::today();
        $sevenDays   = $today->copy()->addDays(7);
        $jatuhTempo7 = (clone $base)
            ->where('sisa_tagihan', '>', 0)
            ->whereBetween('tanggal_jatuh_tempo', [$today->toDateString(), $sevenDays->toDateString()])
            ->sum('sisa_tagihan');

        $overdueRows = (clone $base)
            ->where('sisa_tagihan', '>', 0)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->where('tanggal_jatuh_tempo', '<', $today->toDateString())
            ->get(['tanggal_jatuh_tempo', 'sisa_tagihan']);

        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($overdueRows as $row) {
            $days = $today->diffInDays(Carbon::parse($row->tanggal_jatuh_tempo), false) * -1;
            $key  = match (true) {
                $days <= 0   => 'current',
                $days <= 30  => '1-30',
                $days <= 60  => '31-60',
                $days <= 90  => '61-90',
                default      => '90+',
            };
            $buckets[$key] += (float) $row->sisa_tagihan;
        }

        $topVendors = (clone $base)
            ->where('sisa_tagihan', '>', 0)
            ->selectRaw('vendor_ap_id, SUM(sisa_tagihan) as total_outstanding')
            ->with('vendorAp:id,kode_vendor,nama_vendor')
            ->groupBy('vendor_ap_id')
            ->orderByDesc('total_outstanding')
            ->limit(5)
            ->get()
            ->map(fn($row) => [
                'vendor_ap_id'      => $row->vendor_ap_id,
                'nama_vendor'       => $row->vendorAp?->nama_vendor,
                'kode_vendor'       => $row->vendorAp?->kode_vendor,
                'total_outstanding' => (float) $row->total_outstanding,
            ]);

        $bulanLalu = $today->copy()->subMonthNoOverflow();

        $bulanIni = \App\Models\PembayaranAp::whereHas('tagihanAp', fn($q) => $this->applyScopeToTagihan($q, $filters))
            ->whereMonth('tanggal_pembayaran', $today->month)
            ->whereYear('tanggal_pembayaran', $today->year)
            ->sum('jumlah_pembayaran');

        $totalBulanLalu = \App\Models\PembayaranAp::whereHas('tagihanAp', fn($q) => $this->applyScopeToTagihan($q, $filters))
            ->whereMonth('tanggal_pembayaran', $bulanLalu->month)
            ->whereYear('tanggal_pembayaran', $bulanLalu->year)
            ->sum('jumlah_pembayaran');

        return [
            'total_outstanding'   => (float) $totalOutstanding,
            'jatuh_tempo_7_hari'  => (float) $jatuhTempo7,
            'overdue_buckets'     => $buckets,
            'top_vendors'         => $topVendors,
            'pembayaran_bulan_ini' => (float) $bulanIni,
            'pembayaran_bulan_lalu' => (float) $totalBulanLalu,
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
