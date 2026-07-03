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
            ->with(['klienAr.perusahaan', 'klienAr.karyawanAr'])
            ->where('is_opening_balance', false)
            ->whereNotIn('status', ['LUNAS'])
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $v)))
            ->when($segmentTypes, fn($q) => $q->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes)))
            ->get();

        $obInvoices = Invoice::query()
            ->with(['klienAr.perusahaan', 'klienAr.karyawanAr', 'openingBalanceDetails'])
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
            $anchor = $inv->tanggal_jatuh_tempo ?: $inv->tanggal_invoice;
            $lines->push($this->makeLine($inv->klien_ar_id, $inv->klienAr, [
                'no_invoice'          => $inv->no_invoice,
                'tanggal_invoice'     => $inv->tanggal_invoice,
                'tanggal_jatuh_tempo' => $inv->tanggal_jatuh_tempo,
                'tanggal_anchor'      => $anchor,
                'total_tagihan'       => (float) $inv->total_tagihan,
                'total_pembayaran'    => (float) $inv->total_pembayaran,
                'sisa'                => (float) $inv->sisa_tagihan,
                'status'              => $inv->status,
            ]));
        }

        foreach ($obInvoices as $ob) {
            if ($ob->openingBalanceDetails->isNotEmpty()) {
                foreach ($ob->openingBalanceDetails as $detail) {
                    // Opening Balance tidak memiliki tanggal jatuh tempo → anchor = tanggal invoice asal.
                    $anchor = $detail->tanggal_invoice_asal ?: $ob->tanggal_invoice;
                    $sisa   = (float) $detail->sisa_tagihan_asal;
                    $tagih  = (float) $detail->jumlah_tagihan_asal;
                    $lines->push($this->makeLine($ob->klien_ar_id, $ob->klienAr, [
                        'no_invoice'          => $detail->no_invoice_asal,
                        'tanggal_invoice'     => $detail->tanggal_invoice_asal,
                        'tanggal_jatuh_tempo' => null,
                        'tanggal_anchor'      => $anchor,
                        'total_tagihan'       => $tagih,
                        'total_pembayaran'    => $tagih - $sisa,
                        'sisa'                => $sisa,
                        'status'              => 'OPENING BALANCE',
                    ]));
                }
            } else {
                $anchor = $ob->tanggal_jatuh_tempo ?: $ob->tanggal_invoice;
                $lines->push($this->makeLine($ob->klien_ar_id, $ob->klienAr, [
                    'no_invoice'          => $ob->no_invoice,
                    'tanggal_invoice'     => $ob->tanggal_invoice,
                    'tanggal_jatuh_tempo' => $ob->tanggal_jatuh_tempo,
                    'tanggal_anchor'      => $anchor,
                    'total_tagihan'       => (float) $ob->total_tagihan,
                    'total_pembayaran'    => (float) $ob->total_pembayaran,
                    'sisa'                => (float) $ob->sisa_tagihan,
                    'status'              => 'OPENING BALANCE',
                ]));
            }
        }

        $grouped = $lines->groupBy('klien_ar_id');

        $rows = $grouped->map(function (Collection $group) use ($asOf) {
            $klien   = $group->first()['klien'];
            $buckets = ['current' => 0, 'hari_1_30' => 0, 'hari_31_60' => 0, 'hari_61_90' => 0, 'hari_91_plus' => 0];
            $details = [];

            foreach ($group as $line) {
                $ageDays = (int) Carbon::parse($line['tanggal_anchor'])->startOfDay()->diffInDays($asOf, false);
                $bucket  = $this->classifyBucket($ageDays);
                $buckets[$bucket] += $line['sisa'];

                $umur = $line['tanggal_invoice']
                    ? (int) Carbon::parse($line['tanggal_invoice'])->startOfDay()->diffInDays($asOf, false)
                    : null;

                $details[] = [
                    'no_invoice'          => $line['no_invoice'],
                    'tanggal_invoice'     => $line['tanggal_invoice'] ? Carbon::parse($line['tanggal_invoice'])->toDateString() : null,
                    'tanggal_jatuh_tempo' => $line['tanggal_jatuh_tempo'] ? Carbon::parse($line['tanggal_jatuh_tempo'])->toDateString() : null,
                    'umur_invoice'        => $umur,
                    'hari_terlambat'      => max(0, $ageDays),
                    'bucket'              => $bucket,
                    'total_tagihan'       => $line['total_tagihan'],
                    'total_pembayaran'    => $line['total_pembayaran'],
                    'sisa_tagihan'        => $line['sisa'],
                    'status'              => $line['status'],
                    'pic_ar'              => $line['pic_ar'],
                    'perusahaan'          => $line['perusahaan'],
                ];
            }

            $total = array_sum($buckets);

            return [
                'klien_id'     => $klien?->id,
                'kode_klien'   => $klien?->kode_klien,
                'nama_klien'   => $klien?->nama_klien,
                'perusahaan'   => $klien?->perusahaan?->nama_singkatan_perusahaan,
                'pic_ar'       => $klien?->karyawanAr?->nama_karyawan,
                'current'      => $buckets['current'],
                'hari_1_30'    => $buckets['hari_1_30'],
                'hari_31_60'   => $buckets['hari_31_60'],
                'hari_61_90'   => $buckets['hari_61_90'],
                'hari_91_plus' => $buckets['hari_91_plus'],
                'total'        => $total,
                'details'      => $details,
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

    private function makeLine($klienArId, $klien, array $data): array
    {
        return array_merge([
            'klien_ar_id' => $klienArId,
            'klien'       => $klien,
            'pic_ar'      => $klien?->karyawanAr?->nama_karyawan,
            'perusahaan'  => $klien?->perusahaan?->nama_singkatan_perusahaan,
        ], $data);
    }

    private function classifyBucket(int $ageDays): string
    {
        if ($ageDays <= 0)  return 'current';
        if ($ageDays <= 30) return 'hari_1_30';
        if ($ageDays <= 60) return 'hari_31_60';
        if ($ageDays <= 90) return 'hari_61_90';

        return 'hari_91_plus';
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
