<?php

namespace App\Domain\Finance\JatuhTempo\Services;

use App\Models\Invoice;
use Carbon\Carbon;

class JatuhTempoService
{
    public function getReport(array $filters = []): array
    {
        $today = Carbon::today();
        $days  = (int) ($filters['days'] ?? 30);

        $query = Invoice::query()
            ->with(['klienAr.perusahaan', 'klienAr.karyawanAr'])
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where('sisa_tagihan', '>', 0)
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->whereHas('klienAr', fn($kq) => $kq->where('karyawan_ar_id', $v)));

        // Filter by rentang jatuh tempo
        if (($filters['filter_type'] ?? 'upcoming') === 'overdue') {
            $query->where('tanggal_jatuh_tempo', '<', $today->toDateString());
        } elseif (($filters['filter_type'] ?? 'upcoming') === 'upcoming') {
            $query->whereBetween('tanggal_jatuh_tempo', [
                $today->toDateString(),
                $today->copy()->addDays($days)->toDateString(),
            ]);
        }
        // 'all' = tidak filter tanggal

        $invoices = $query->orderBy('tanggal_jatuh_tempo', 'asc')->get();

        $rows = $invoices->map(function (Invoice $inv) use ($today) {
            $klien      = $inv->klienAr;
            $dueDate    = $inv->tanggal_jatuh_tempo
                ? Carbon::parse($inv->tanggal_jatuh_tempo)
                : null;

            $selisihHari = $dueDate ? (int) $today->diffInDays($dueDate, false) : null;

            return [
                'invoice_id'          => $inv->id,
                'no_invoice'          => $inv->no_invoice,
                'tanggal_invoice'     => $inv->tanggal_invoice?->toDateString(),
                'tanggal_jatuh_tempo' => $dueDate?->toDateString(),
                'selisih_hari'        => $selisihHari,
                'status'              => $inv->status,
                'total_tagihan'       => (float) $inv->total_tagihan,
                'total_pembayaran'    => (float) $inv->total_pembayaran,
                'sisa_tagihan'        => (float) $inv->sisa_tagihan,
                'klien_id'            => $klien?->id,
                'kode_klien'          => $klien?->kode_klien,
                'nama_klien'          => $klien?->nama_klien,
                'perusahaan'          => $klien?->perusahaan?->nama_singkatan_perusahaan,
                'pic_ar'              => $klien?->karyawanAr?->nama_karyawan,
            ];
        })->values()->all();

        $summary = [
            'total_invoice'    => count($rows),
            'total_sisa'       => array_sum(array_column($rows, 'sisa_tagihan')),
            'jatuh_tempo_hari_ini' => count(array_filter($rows, fn($r) => $r['selisih_hari'] === 0)),
            'sudah_lewat'      => count(array_filter($rows, fn($r) => $r['selisih_hari'] !== null && $r['selisih_hari'] < 0)),
        ];

        return [
            'as_of_date' => $today->toDateString(),
            'summary'    => $summary,
            'rows'       => $rows,
        ];
    }
}
