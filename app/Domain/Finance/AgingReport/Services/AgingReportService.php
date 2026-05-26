<?php

namespace App\Domain\Finance\AgingReport\Services;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AgingReportService
{
    public function getReport(array $filters = []): array
    {
        $asOf = isset($filters['as_of_date'])
            ? Carbon::parse($filters['as_of_date'])->startOfDay()
            : Carbon::today();

        $segmentTypes = $this->resolveSegmentTypes($filters['segment'] ?? null);

        $regularInvoices = Invoice::query()
            ->with(['klienAr.perusahaan'])
            ->where('is_opening_balance', false)
            ->whereNotIn('status', ['LUNAS'])
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $v)))
            ->when($segmentTypes, fn($q) => $q->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes)))
            ->get();

        $obInvoices = Invoice::query()
            ->with(['klienAr.perusahaan', 'openingBalanceDetails'])
            ->where('is_opening_balance', true)
            ->where('approval_status', 'APPROVED')
            ->whereNotIn('status', ['LUNAS'])
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $v)))
            ->when($segmentTypes, fn($q) => $q->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes)))
            ->get();

        $lines = collect();

        foreach ($regularInvoices as $inv) {
            $lines->push([
                'klien_ar_id'   => $inv->klien_ar_id,
                'klien'         => $inv->klienAr,
                'tanggal_anchor'=> $inv->tanggal_invoice,
                'sisa'          => (float) $inv->sisa_tagihan,
            ]);
        }

        foreach ($obInvoices as $ob) {
            if ($ob->openingBalanceDetails->isNotEmpty()) {
                foreach ($ob->openingBalanceDetails as $detail) {
                    $lines->push([
                        'klien_ar_id'   => $ob->klien_ar_id,
                        'klien'         => $ob->klienAr,
                        'tanggal_anchor'=> $detail->tanggal_invoice_asal,
                        'sisa'          => (float) $detail->sisa_tagihan_asal,
                    ]);
                }
            } else {
                $lines->push([
                    'klien_ar_id'   => $ob->klien_ar_id,
                    'klien'         => $ob->klienAr,
                    'tanggal_anchor'=> $ob->tanggal_invoice,
                    'sisa'          => (float) $ob->sisa_tagihan,
                ]);
            }
        }

        $grouped = $lines->groupBy('klien_ar_id');

        $rows = $grouped->map(function (Collection $group) use ($asOf) {
            $klien   = $group->first()['klien'];
            $buckets = ['current' => 0, 'hari_1_30' => 0, 'hari_31_60' => 0, 'hari_61_90' => 0, 'hari_91_plus' => 0];

            foreach ($group as $line) {
                $anchor      = Carbon::parse($line['tanggal_anchor'])->startOfDay();
                $ageDays     = $anchor->diffInDays($asOf, false);

                if ($ageDays <= 0) {
                    $buckets['current'] += $line['sisa'];
                } elseif ($ageDays <= 30) {
                    $buckets['hari_1_30'] += $line['sisa'];
                } elseif ($ageDays <= 60) {
                    $buckets['hari_31_60'] += $line['sisa'];
                } elseif ($ageDays <= 90) {
                    $buckets['hari_61_90'] += $line['sisa'];
                } else {
                    $buckets['hari_91_plus'] += $line['sisa'];
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

    private function resolveSegmentTypes(?string $segment): ?array
    {
        return match(strtoupper($segment ?? '')) {
            'B2B'   => ['PT', 'STOKIS'],
            'B2C'   => ['RESTO', 'MITRA'],
            default => null,
        };
    }
}
