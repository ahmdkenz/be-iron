<?php

namespace App\Domain\Finance\Dashboard\Services;

use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardService
{
    public function getPicArOverview(User $user): array
    {
        $user->loadMissing('karyawan');

        $months = $this->buildMonthBuckets();

        if (!$user->karyawan_id) {
            return $this->emptyPicArOverview($user, $months);
        }

        $karyawanId = (int) $user->karyawan_id;
        $klienQuery = KlienAr::query()->where('karyawan_ar_id', $karyawanId);

        // Fetch klien IDs once — reused across all invoice queries to avoid repeated subqueries
        $klienIds   = (clone $klienQuery)->pluck('id');
        $invoiceQuery = Invoice::query()->whereIn('klien_ar_id', $klienIds);

        // total_tagihan/sisa_tagihan per invoice sudah kumulatif (carry-over dari
        // invoice lain milik klien yang sama dalam bulan yang sama), jadi tidak boleh
        // langsung di-SUM lintas invoice — pakai subtotal & sisa milik invoice itu
        // sendiri agar tidak double-count.
        $invoiceAgg = (clone $invoiceQuery)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) as total_tagihan,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total_sisa
            ')
            ->first();

        return [
            'scope' => 'AR',
            'pic_ar' => [
                'user_id'     => $user->id,
                'karyawan_id' => $user->karyawan_id,
                'nama'        => $user->karyawan?->nama_karyawan ?? $user->username,
            ],
            'summary' => [
                'total_klien'       => (clone $klienQuery)->where('status', true)->count(),
                'total_resto_aktif' => (clone $klienQuery)
                    ->whereNotNull('resto_id')
                    ->whereHas('resto', fn(Builder $query) => $query->where('status', true))
                    ->distinct()
                    ->count('resto_id'),
                'total_invoice'     => (clone $invoiceQuery)->count(),
                'total_tagihan'     => (float) ($invoiceAgg?->total_tagihan ?? 0),
                'total_pembayaran'  => (float) ($invoiceAgg?->total_pembayaran ?? 0),
                'total_sisa'        => (float) ($invoiceAgg?->total_sisa ?? 0),
            ],
            'status_breakdown' => $this->buildStatusBreakdown($invoiceQuery),
            'monthly_trend'    => $this->buildMonthlyTrend($months, $klienIds),
            'recent_invoices'  => $this->buildRecentInvoices($invoiceQuery),
            'kpi'              => $this->buildKpiMetrics($klienIds),
        ];
    }

    private function buildStatusBreakdown(?Builder $invoiceQuery = null): array
    {
        $statuses = [
            'DRAFT'    => 'Draft',
            'TERKIRIM' => 'Terkirim',
            'SEBAGIAN' => 'Sebagian',
            'LUNAS'    => 'Lunas',
        ];

        $baseQuery = $invoiceQuery ? clone $invoiceQuery : Invoice::query()->whereRaw('1 = 0');

        $rows = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as count, SUM(subtotal) as total_tagihan, SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)) as total_sisa')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return collect($statuses)->map(function (string $label, string $status) use ($rows) {
            $row = $rows->get($status);

            return [
                'status'        => $status,
                'label'         => $label,
                'count'         => (int) ($row?->count ?? 0),
                'total_tagihan' => (float) ($row?->total_tagihan ?? 0),
                'total_sisa'    => (float) ($row?->total_sisa ?? 0),
            ];
        })->values()->all();
    }

    private function buildMonthlyTrend(Collection $months, \Illuminate\Support\Collection $klienIds): array
    {
        $startDate = $months->first()['start_date'];
        $endDate = $months->last()['end_date'];

        $invoiceTotals = Invoice::query()
            ->selectRaw("DATE_FORMAT(tanggal_invoice, '%Y-%m') as month_key, SUM(subtotal) as total")
            ->whereIn('klien_ar_id', $klienIds)
            ->whereBetween('tanggal_invoice', [$startDate, $endDate])
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $paymentTotals = PembayaranAr::query()
            ->selectRaw("DATE_FORMAT(tanggal_pembayaran, '%Y-%m') as month_key, SUM(jumlah_pembayaran) as total")
            ->whereBetween('tanggal_pembayaran', [$startDate, $endDate])
            ->whereIn('invoice_id', Invoice::whereIn('klien_ar_id', $klienIds)->select('id'))
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        return [
            'labels'         => $months->pluck('label')->all(),
            'invoice_totals' => $months->map(
                fn(array $month) => (float) ($invoiceTotals[$month['key']] ?? 0)
            )->all(),
            'payment_totals' => $months->map(
                fn(array $month) => (float) ($paymentTotals[$month['key']] ?? 0)
            )->all(),
        ];
    }

    private function buildRecentInvoices(Builder $invoiceQuery): array
    {
        return (clone $invoiceQuery)
            ->with(['klienAr.resto'])
            ->latest('tanggal_invoice')
            ->limit(5)
            ->get()
            ->map(fn(Invoice $invoice) => [
                'id'               => $invoice->id,
                'no_invoice'       => $invoice->no_invoice,
                'tanggal_invoice'  => $invoice->tanggal_invoice?->format('d-m-Y'),
                'klien'            => $invoice->klienAr?->nama_klien,
                'resto'            => $invoice->klienAr?->resto?->nama_resto,
                'total_tagihan'    => (float) $invoice->total_tagihan,
                'total_pembayaran' => (float) $invoice->total_pembayaran,
                'sisa_tagihan'     => (float) $invoice->sisa_tagihan,
                'status'           => $invoice->status,
            ])
            ->values()
            ->all();
    }

    private function buildMonthBuckets(int $totalMonths = 6, ?Carbon $startFrom = null): Collection
    {
        $startMonth = $startFrom ?? now()->startOfMonth()->subMonths($totalMonths - 1);

        return collect(range(0, $totalMonths - 1))->map(function (int $offset) use ($startMonth) {
            $month = $startMonth->copy()->addMonths($offset);

            return [
                'key'        => $month->format('Y-m'),
                'label'      => $month->locale('id')->translatedFormat('M Y'),
                'start_date' => $month->copy()->startOfMonth()->toDateString(),
                'end_date'   => $month->copy()->endOfMonth()->toDateString(),
            ];
        });
    }

    private function buildDayBuckets(Carbon $start, Carbon $end): Collection
    {
        $days = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->endOfDay());

        return collect(range(0, $days))->map(function (int $offset) use ($start) {
            $day = $start->copy()->startOfDay()->addDays($offset);

            return [
                'key'        => $day->format('Y-m-d'),
                'label'      => $day->locale('id')->translatedFormat('d M'),
                'start_date' => $day->copy()->startOfDay()->toDateString(),
                'end_date'   => $day->copy()->endOfDay()->toDateString(),
            ];
        });
    }

    private function buildYtdBuckets(): Collection
    {
        $startOfYear  = Carbon::now()->startOfYear();
        $currentMonth = Carbon::now()->startOfMonth();
        $totalMonths  = (int) $startOfYear->diffInMonths($currentMonth) + 1;

        return $this->buildMonthBuckets($totalMonths, $startOfYear);
    }

    private function buildBuckets(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            '1D'  => [$this->buildDayBuckets($now->copy()->startOfDay(), $now->copy()->endOfDay()), 'daily'],
            '1W'  => [$this->buildDayBuckets($now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()), 'daily'],
            '1M'  => [$this->buildDayBuckets($now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()), 'daily'],
            '3M'  => [$this->buildMonthBuckets(3), 'monthly'],
            'YTD' => [$this->buildYtdBuckets(), 'monthly'],
            '1Y'  => [$this->buildMonthBuckets(12), 'monthly'],
            '3Y'  => [$this->buildMonthBuckets(36), 'monthly'],
            '5Y'  => [$this->buildMonthBuckets(60), 'monthly'],
            default => [$this->buildMonthBuckets(6), 'monthly'],
        };
    }

    private function buildTrend(Collection $buckets, Collection $klienIds, string $granularity): array
    {
        $startDate  = $buckets->first()['start_date'];
        $endDate    = $buckets->last()['end_date'];
        $dateFormat = $granularity === 'daily' ? '%Y-%m-%d' : '%Y-%m';

        $invoiceTotals = Invoice::query()
            ->selectRaw("DATE_FORMAT(tanggal_invoice, ?) as bucket_key, SUM(subtotal) as total", [$dateFormat])
            ->whereIn('klien_ar_id', $klienIds)
            ->whereBetween('tanggal_invoice', [$startDate, $endDate])
            ->groupBy('bucket_key')
            ->pluck('total', 'bucket_key');

        $paymentTotals = PembayaranAr::query()
            ->selectRaw("DATE_FORMAT(tanggal_pembayaran, ?) as bucket_key, SUM(jumlah_pembayaran) as total", [$dateFormat])
            ->whereBetween('tanggal_pembayaran', [$startDate, $endDate])
            ->whereIn('invoice_id', Invoice::whereIn('klien_ar_id', $klienIds)->select('id'))
            ->groupBy('bucket_key')
            ->pluck('total', 'bucket_key');

        return [
            'labels'         => $buckets->pluck('label')->all(),
            'invoice_totals' => $buckets->map(fn(array $b) => (float) ($invoiceTotals[$b['key']] ?? 0))->all(),
            'payment_totals' => $buckets->map(fn(array $b) => (float) ($paymentTotals[$b['key']] ?? 0))->all(),
        ];
    }

    public function getGlobalOverview(User $user, string $period = '6M'): array
    {
        [$buckets, $granularity] = $this->buildBuckets($period);
        $klienIds     = KlienAr::query()->pluck('id');
        $invoiceQuery = Invoice::query()->whereIn('klien_ar_id', $klienIds);

        // total_tagihan/sisa_tagihan per invoice sudah kumulatif (carry-over dari
        // invoice lain milik klien yang sama dalam bulan yang sama), jadi tidak boleh
        // langsung di-SUM lintas invoice — pakai subtotal & sisa milik invoice itu
        // sendiri agar tidak double-count.
        $invoiceAgg = (clone $invoiceQuery)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) as total_tagihan,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total_sisa
            ')
            ->first();

        return [
            'scope' => 'GLOBAL',
            'summary' => [
                'total_klien'       => KlienAr::where('status', true)->count(),
                'total_invoice'     => (clone $invoiceQuery)->count(),
                'total_tagihan'     => (float) ($invoiceAgg?->total_tagihan ?? 0),
                'total_pembayaran'  => (float) ($invoiceAgg?->total_pembayaran ?? 0),
                'total_sisa'        => (float) ($invoiceAgg?->total_sisa ?? 0),
            ],
            'status_breakdown' => $this->buildStatusBreakdown($invoiceQuery),
            'monthly_trend'    => $this->buildTrend($buckets, $klienIds, $granularity),
            'recent_invoices'  => $this->buildRecentInvoices($invoiceQuery),
            'kpi'              => $this->buildKpiMetrics($klienIds),
        ];
    }

    public function getKpiMetrics(User $user): array
    {
        $user->loadMissing('karyawan');

        if (!$user->karyawan_id) {
            return $this->emptyKpi();
        }

        $klienIds = KlienAr::where('karyawan_ar_id', $user->karyawan_id)->pluck('id');

        return $this->buildKpiMetrics($klienIds);
    }

    public function getGlobalKpiMetrics(): array
    {
        $klienIds = KlienAr::pluck('id');

        return $this->buildKpiMetrics($klienIds);
    }

    private function buildKpiMetrics(Collection $klienIds): array
    {
        $today = Carbon::today();

        $invoiceBase = Invoice::query()
            ->whereIn('klien_ar_id', $klienIds)
            ->where('sisa_tagihan', '>', 0)
            ->whereNotIn('status', ['DRAFT', 'LUNAS']);

        // total_tagihan/sisa_tagihan per invoice sudah kumulatif (carry-over), tidak
        // boleh langsung di-SUM lintas invoice — pakai subtotal & sisa milik invoice
        // itu sendiri agar tidak double-count.
        $invoiceAgg = Invoice::whereIn('klien_ar_id', $klienIds)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) as total_tagihan,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total_sisa
            ')
            ->first();

        $totalTagihan    = (float) ($invoiceAgg?->total_tagihan ?? 0);
        $totalPembayaran = (float) ($invoiceAgg?->total_pembayaran ?? 0);
        $totalSisa       = (float) ($invoiceAgg?->total_sisa ?? 0);
        $totalInvoice    = Invoice::whereIn('klien_ar_id', $klienIds)->count();

        // Collection Rate: gunakan (tagihan - sisa) agar tidak melebihi 100% saat invoice overpaid
        $collectionRate = $totalTagihan > 0 ? round(($totalTagihan - $totalSisa) / $totalTagihan * 100, 1) : 0;

        // DSO: rata-rata hari piutang yang belum terbayar, dihitung dari seluruh outstanding
        // DSO = (Sisa Piutang / Total Tagihan) × 30 hari (estimasi per bulan)
        $dso = $totalTagihan > 0 ? round(($totalSisa / $totalTagihan) * 30, 1) : 0;

        // Invoice Overdue: invoice dengan tanggal_invoice > 30 hari yang masih ada sisa
        $overdueCount = (clone $invoiceBase)
            ->where('tanggal_invoice', '<=', $today->copy()->subDays(30))
            ->count();

        $overduePercentage = $totalInvoice > 0 ? round(($overdueCount / $totalInvoice) * 100, 1) : 0;

        // Aging summary (5 bucket)
        $agingSummary = $this->buildAgingSummaryForKpi($klienIds, $today);

        return [
            'dso'                => $dso,
            'collection_rate'    => $collectionRate,
            'overdue_count'      => $overdueCount,
            'overdue_percentage' => $overduePercentage,
            'aging_summary'      => $agingSummary,
        ];
    }

    private function buildAgingSummaryForKpi(Collection $klienIds, Carbon $today): array
    {
        // sisa_tagihan per invoice sudah kumulatif (carry-over dari invoice lain milik
        // klien yang sama dalam bulan yang sama) — kalau dipakai apa adanya di sini,
        // invoice lama yang belum lunas ikut terhitung ulang di bucket umur invoice
        // baru yang mewarisi carryover-nya. Pakai sisa milik invoice itu sendiri.
        $invoices = Invoice::query()
            ->whereIn('klien_ar_id', $klienIds)
            ->whereRaw('GREATEST(0, subtotal - total_pembayaran - total_penyesuaian) > 0')
            ->whereNotIn('status', ['LUNAS'])
            ->get(['tanggal_invoice', 'subtotal', 'total_pembayaran', 'total_penyesuaian']);

        $buckets = ['current' => 0.0, 'hari_1_30' => 0.0, 'hari_31_60' => 0.0, 'hari_61_90' => 0.0, 'hari_91_plus' => 0.0];

        foreach ($invoices as $inv) {
            $ageDays = Carbon::parse($inv->tanggal_invoice)->startOfDay()->diffInDays($today, false);
            $sisa    = max(0.0, (float) $inv->subtotal - (float) $inv->total_pembayaran - (float) $inv->total_penyesuaian);

            if ($ageDays <= 0) {
                $buckets['current'] += $sisa;
            } elseif ($ageDays <= 30) {
                $buckets['hari_1_30'] += $sisa;
            } elseif ($ageDays <= 60) {
                $buckets['hari_31_60'] += $sisa;
            } elseif ($ageDays <= 90) {
                $buckets['hari_61_90'] += $sisa;
            } else {
                $buckets['hari_91_plus'] += $sisa;
            }
        }

        return $buckets;
    }

    public function buildTopClients(int $limit = 5): array
    {
        // sisa_tagihan/total_tagihan per invoice sudah kumulatif (carry-over), tidak
        // boleh langsung di-SUM lintas invoice per klien — pakai subtotal & sisa
        // milik invoice itu sendiri agar tidak double-count.
        return KlienAr::query()
            ->join('tb_invoice', 'tb_klien_ar.id', '=', 'tb_invoice.klien_ar_id')
            ->selectRaw('tb_klien_ar.id, tb_klien_ar.nama_klien, tb_klien_ar.kode_klien, SUM(GREATEST(0, tb_invoice.subtotal - tb_invoice.total_pembayaran - tb_invoice.total_penyesuaian)) as total_sisa, SUM(tb_invoice.subtotal) as total_tagihan, COUNT(tb_invoice.id) as jumlah_invoice')
            ->where('tb_klien_ar.status', true)
            ->groupBy('tb_klien_ar.id', 'tb_klien_ar.nama_klien', 'tb_klien_ar.kode_klien')
            ->having('total_sisa', '>', 0)
            ->orderByDesc('total_sisa')
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'id'             => $row->id,
                'nama_klien'     => $row->nama_klien,
                'kode_klien'     => $row->kode_klien,
                'total_sisa'     => (float) $row->total_sisa,
                'total_tagihan'  => (float) $row->total_tagihan,
                'jumlah_invoice' => (int) $row->jumlah_invoice,
            ])
            ->values()
            ->all();
    }

    private function emptyKpi(): array
    {
        return [
            'dso'                => 0,
            'collection_rate'    => 0,
            'overdue_count'      => 0,
            'overdue_percentage' => 0,
            'aging_summary'      => [
                'current'      => 0,
                'hari_1_30'    => 0,
                'hari_31_60'   => 0,
                'hari_61_90'   => 0,
                'hari_91_plus' => 0,
            ],
        ];
    }

    private function emptyPicArOverview(User $user, Collection $months): array
    {
        return [
            'scope' => 'AR',
            'pic_ar' => [
                'user_id'     => $user->id,
                'karyawan_id' => $user->karyawan_id,
                'nama'        => $user->karyawan?->nama_karyawan ?? $user->username,
            ],
            'summary' => [
                'total_klien'       => 0,
                'total_resto_aktif' => 0,
                'total_invoice'     => 0,
                'total_tagihan'     => 0,
                'total_pembayaran'  => 0,
                'total_sisa'        => 0,
            ],
            'status_breakdown' => $this->buildStatusBreakdown(),
            'monthly_trend' => [
                'labels'         => $months->pluck('label')->all(),
                'invoice_totals' => $months->map(fn() => 0)->all(),
                'payment_totals' => $months->map(fn() => 0)->all(),
            ],
            'recent_invoices' => [],
        ];
    }
}
