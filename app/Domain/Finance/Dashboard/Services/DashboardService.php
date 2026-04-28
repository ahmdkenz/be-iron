<?php

namespace App\Domain\Finance\Dashboard\Services;

use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use App\Models\User;
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
                'total_tagihan'     => (float) (clone $invoiceQuery)->sum('total_tagihan'),
                'total_pembayaran'  => (float) (clone $invoiceQuery)->sum('total_pembayaran'),
                'total_sisa'        => (float) (clone $invoiceQuery)->sum('sisa_tagihan'),
            ],
            'status_breakdown' => $this->buildStatusBreakdown($invoiceQuery),
            'monthly_trend'    => $this->buildMonthlyTrend($months, $klienIds),
            'recent_invoices'  => $this->buildRecentInvoices($invoiceQuery),
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
            ->selectRaw('status, COUNT(*) as count, SUM(total_tagihan) as total_tagihan, SUM(sisa_tagihan) as total_sisa')
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
            ->selectRaw("DATE_FORMAT(tanggal_invoice, '%Y-%m') as month_key, SUM(total_tagihan) as total")
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
                'tanggal_invoice'  => $invoice->tanggal_invoice?->format('Y-m-d'),
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

    private function buildMonthBuckets(int $totalMonths = 6): Collection
    {
        $startMonth = now()->startOfMonth()->subMonths($totalMonths - 1);

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
