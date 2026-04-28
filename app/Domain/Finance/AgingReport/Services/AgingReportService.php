<?php

namespace App\Domain\Finance\AgingReport\Services;

use App\Models\Invoice;
use App\Models\KlienAr;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AgingReportService
{
    public function getReport(array $filters = []): array
    {
        $asOf = isset($filters['as_of_date'])
            ? Carbon::parse($filters['as_of_date'])->startOfDay()
            : Carbon::today();

        $query = Invoice::query()
            ->with(['klienAr.perusahaan'])
            ->whereNotIn('status', ['LUNAS'])
            ->where('is_opening_balance', false)
            ->orWhere(function ($q) {
                $q->where('is_opening_balance', true)
                  ->where('approval_status', 'APPROVED')
                  ->whereNotIn('status', ['LUNAS']);
            });

        // Re-scope: combine both conditions properly
        $query = Invoice::query()
            ->with(['klienAr.perusahaan'])
            ->where(function ($q) {
                $q->where('is_opening_balance', false)
                  ->orWhere(function ($inner) {
                      $inner->where('is_opening_balance', true)
                            ->where('approval_status', 'APPROVED');
                  });
            })
            ->whereNotIn('status', ['LUNAS'])
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v));

        $invoices = $query->get();

        $grouped = $invoices->groupBy('klien_ar_id');

        $rows = $grouped->map(function (Collection $group) use ($asOf) {
            $first   = $group->first();
            $klien   = $first->klienAr;
            $buckets = ['current' => 0, 'hari_1_30' => 0, 'hari_31_60' => 0, 'hari_61_90' => 0, 'hari_91_plus' => 0];

            foreach ($group as $invoice) {
                $sisa = (float) $invoice->sisa_tagihan;

                if (!$invoice->tanggal_jatuh_tempo) {
                    $buckets['current'] += $sisa;
                    continue;
                }

                $dueDate      = Carbon::parse($invoice->tanggal_jatuh_tempo)->startOfDay();
                $overdueDays  = $dueDate->diffInDays($asOf, false);

                if ($overdueDays <= 0) {
                    $buckets['current'] += $sisa;
                } elseif ($overdueDays <= 30) {
                    $buckets['hari_1_30'] += $sisa;
                } elseif ($overdueDays <= 60) {
                    $buckets['hari_31_60'] += $sisa;
                } elseif ($overdueDays <= 90) {
                    $buckets['hari_61_90'] += $sisa;
                } else {
                    $buckets['hari_91_plus'] += $sisa;
                }
            }

            $total = array_sum($buckets);

            return [
                'klien_id'     => $klien?->id,
                'kode_klien'   => $klien?->kode_klien,
                'nama_klien'   => $klien?->nama_klien,
                'perusahaan'   => $klien?->perusahaan?->nama_singkatan_perusahaan,
                'current'      => $buckets['current'],
                'hari_1_30'    => $buckets['hari_1_30'],
                'hari_31_60'   => $buckets['hari_31_60'],
                'hari_61_90'   => $buckets['hari_61_90'],
                'hari_91_plus' => $buckets['hari_91_plus'],
                'total'        => $total,
            ];
        })->values()->all();

        $summary = [
            'current'      => array_sum(array_column($rows, 'current')),
            'hari_1_30'    => array_sum(array_column($rows, 'hari_1_30')),
            'hari_31_60'   => array_sum(array_column($rows, 'hari_31_60')),
            'hari_61_90'   => array_sum(array_column($rows, 'hari_61_90')),
            'hari_91_plus' => array_sum(array_column($rows, 'hari_91_plus')),
            'total'        => array_sum(array_column($rows, 'total')),
        ];

        return [
            'as_of_date' => $asOf->toDateString(),
            'summary'    => $summary,
            'rows'       => $rows,
        ];
    }
}
