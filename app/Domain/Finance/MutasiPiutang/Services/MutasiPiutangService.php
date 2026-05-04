<?php

namespace App\Domain\Finance\MutasiPiutang\Services;

use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MutasiPiutangService
{
    public function getReport(array $filters = []): array
    {
        $from = isset($filters['periode_awal'])
            ? Carbon::parse($filters['periode_awal'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = isset($filters['periode_akhir'])
            ? Carbon::parse($filters['periode_akhir'])->endOfDay()
            : Carbon::now()->endOfMonth();

        $klienFilter = $filters['klien_ar_id'] ?? null;

        // Invoice masuk dalam periode (per klien)
        $invoiceMasukQuery = Invoice::query()
            ->select('klien_ar_id', DB::raw('SUM(total_tagihan) as invoice_masuk'))
            ->whereBetween('tanggal_invoice', [$from->toDateString(), $to->toDateString()])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->when($klienFilter, fn($q) => $q->where('klien_ar_id', $klienFilter))
            ->groupBy('klien_ar_id')
            ->pluck('invoice_masuk', 'klien_ar_id');

        // Pembayaran dalam periode (per klien via join invoice)
        $pembayaranQuery = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->select('tb_invoice.klien_ar_id', DB::raw('SUM(tb_pembayaran_ar.jumlah_pembayaran) as pembayaran'))
            ->whereBetween('tb_pembayaran_ar.tanggal_pembayaran', [$from->toDateString(), $to->toDateString()])
            ->when($klienFilter, fn($q) => $q->where('tb_invoice.klien_ar_id', $klienFilter))
            ->groupBy('tb_invoice.klien_ar_id')
            ->pluck('pembayaran', 'klien_ar_id');

        // Total tagihan sebelum periode (saldo awal part 1)
        $tagihanSebelumQuery = Invoice::query()
            ->select('klien_ar_id', DB::raw('SUM(total_tagihan) as total_tagihan'))
            ->where('tanggal_invoice', '<', $from->toDateString())
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->when($klienFilter, fn($q) => $q->where('klien_ar_id', $klienFilter))
            ->groupBy('klien_ar_id')
            ->pluck('total_tagihan', 'klien_ar_id');

        // Total pembayaran sebelum periode (saldo awal part 2)
        $pembayaranSebelumQuery = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->select('tb_invoice.klien_ar_id', DB::raw('SUM(tb_pembayaran_ar.jumlah_pembayaran) as pembayaran'))
            ->where('tb_pembayaran_ar.tanggal_pembayaran', '<', $from->toDateString())
            ->when($klienFilter, fn($q) => $q->where('tb_invoice.klien_ar_id', $klienFilter))
            ->groupBy('tb_invoice.klien_ar_id')
            ->pluck('pembayaran', 'klien_ar_id');

        // Kumpulkan semua klien_ar_id yang relevan
        $klienIds = collect([
            $invoiceMasukQuery->keys(),
            $pembayaranQuery->keys(),
            $tagihanSebelumQuery->keys(),
            $pembayaranSebelumQuery->keys(),
        ])->flatten()->unique()->values();

        if ($klienIds->isEmpty()) {
            return [
                'periode_awal'  => $from->toDateString(),
                'periode_akhir' => $to->toDateString(),
                'summary'       => ['saldo_awal' => 0, 'invoice_masuk' => 0, 'pembayaran' => 0, 'saldo_akhir' => 0],
                'rows'          => [],
            ];
        }

        $klienMap = KlienAr::with('perusahaan')
            ->whereIn('id', $klienIds)
            ->get()
            ->keyBy('id');

        $rows = $klienIds->map(function ($klienId) use (
            $invoiceMasukQuery, $pembayaranQuery,
            $tagihanSebelumQuery, $pembayaranSebelumQuery,
            $klienMap
        ) {
            $klien         = $klienMap->get($klienId);
            $tagihanSebelum  = (float) ($tagihanSebelumQuery[$klienId] ?? 0);
            $bayarSebelum    = (float) ($pembayaranSebelumQuery[$klienId] ?? 0);
            $invoiceMasuk    = (float) ($invoiceMasukQuery[$klienId] ?? 0);
            $pembayaran      = (float) ($pembayaranQuery[$klienId] ?? 0);

            $saldoAwal   = $tagihanSebelum - $bayarSebelum;
            $saldoAkhir  = $saldoAwal + $invoiceMasuk - $pembayaran;

            return [
                'klien_id'      => $klienId,
                'kode_klien'    => $klien?->kode_klien,
                'nama_klien'    => $klien?->nama_klien,
                'perusahaan'    => $klien?->perusahaan?->nama_singkatan_perusahaan,
                'saldo_awal'    => max(0, $saldoAwal),
                'invoice_masuk' => $invoiceMasuk,
                'pembayaran'    => $pembayaran,
                'saldo_akhir'   => max(0, $saldoAkhir),
            ];
        })->sortBy('nama_klien')->values()->all();

        $summary = [
            'saldo_awal'    => array_sum(array_column($rows, 'saldo_awal')),
            'invoice_masuk' => array_sum(array_column($rows, 'invoice_masuk')),
            'pembayaran'    => array_sum(array_column($rows, 'pembayaran')),
            'saldo_akhir'   => array_sum(array_column($rows, 'saldo_akhir')),
        ];

        return [
            'periode_awal'  => $from->toDateString(),
            'periode_akhir' => $to->toDateString(),
            'summary'       => $summary,
            'rows'          => $rows,
        ];
    }
}
