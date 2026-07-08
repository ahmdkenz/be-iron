<?php

namespace App\Domain\Finance\MutasiPiutang\Services;

use App\Models\EndingBalance;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MutasiPiutangService
{
    public function getReport(array $filters = []): array
    {
        $from = isset($filters['periode_awal'])
            ? Carbon::parse($filters['periode_awal'])->startOfDay()
            : null;

        $to = isset($filters['periode_akhir'])
            ? Carbon::parse($filters['periode_akhir'])->endOfDay()
            : null;

        $klienFilter  = $filters['klien_ar_id'] ?? null;
        $segmentTypes = $this->resolveSegmentTypes($filters['segment'] ?? null);
        $perusahaanFilter = $filters['perusahaan_id'] ?? null;
        $picArFilter      = $filters['pic_ar_karyawan_id'] ?? null;

        // Invoice masuk dalam periode (per klien)
        $invoiceMasukQuery = Invoice::query()
            ->select(
                'klien_ar_id',
                DB::raw('SUM(CASE WHEN subtotal = 0 THEN total_tagihan ELSE subtotal END) as invoice_masuk'),
                DB::raw('COUNT(*) as jumlah_invoice_masuk')
            )
            ->when($from, fn($q) => $q->whereDate('tanggal_invoice', '>=', $from->toDateString()))
            ->when($to, fn($q) => $q->whereDate('tanggal_invoice', '<=', $to->toDateString()))
            ->where(fn($q) => $this->approvedInvoiceScope($q))
            ->when($klienFilter, fn($q) => $q->where('klien_ar_id', $klienFilter))
            ->when($perusahaanFilter, fn($q) => $q->where('perusahaan_id', $perusahaanFilter))
            ->when($segmentTypes, fn($q) => $q->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes)))
            ->when($picArFilter, fn($q) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $picArFilter)))
            ->groupBy('klien_ar_id')
            ->get()
            ->keyBy('klien_ar_id');

        // Pembayaran dalam periode (per klien via join invoice)
        $pembayaranQuery = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->join('tb_klien_ar', 'tb_invoice.klien_ar_id', '=', 'tb_klien_ar.id')
            ->select(
                'tb_invoice.klien_ar_id',
                DB::raw('SUM(tb_pembayaran_ar.jumlah_pembayaran) as pembayaran'),
                DB::raw('COUNT(tb_pembayaran_ar.id) as jumlah_pembayaran')
            )
            ->when($from, fn($q) => $q->whereDate('tb_pembayaran_ar.tanggal_pembayaran', '>=', $from->toDateString()))
            ->when($to, fn($q) => $q->whereDate('tb_pembayaran_ar.tanggal_pembayaran', '<=', $to->toDateString()))
            ->when($klienFilter, fn($q) => $q->where('tb_invoice.klien_ar_id', $klienFilter))
            ->when($perusahaanFilter, fn($q) => $q->where('tb_invoice.perusahaan_id', $perusahaanFilter))
            ->when($segmentTypes, fn($q) => $q->whereIn('tb_klien_ar.tipe_klien', $segmentTypes))
            ->when($picArFilter, fn($q) => $q->where('tb_klien_ar.karyawan_ar_id', $picArFilter))
            ->groupBy('tb_invoice.klien_ar_id')
            ->get()
            ->keyBy('klien_ar_id');

        // Saldo awal fallback: outstanding invoice historis sebelum periode.
        // Tanpa $from (tampilkan semua histori), tidak ada "sebelum periode" -> saldo awal = 0 untuk semua klien.
        $saldoAwalFallback = $from
            ? Invoice::query()
                ->select(
                    'klien_ar_id',
                    DB::raw('COALESCE(SUM(GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - COALESCE(total_penyesuaian, 0) END)), 0) as saldo_awal')
                )
                ->where('tanggal_invoice', '<', $from->toDateString())
                ->where(fn($q) => $this->approvedInvoiceScope($q))
                ->when($klienFilter, fn($q) => $q->where('klien_ar_id', $klienFilter))
                ->when($perusahaanFilter, fn($q) => $q->where('perusahaan_id', $perusahaanFilter))
                ->when($segmentTypes, fn($q) => $q->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes)))
                ->when($picArFilter, fn($q) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $picArFilter)))
                ->groupBy('klien_ar_id')
                ->pluck('saldo_awal', 'klien_ar_id')
            : collect();

        // Cek apakah ada EB LOCKED tepat sebelum periode ini (carry-forward snapshot)
        $ebSnapshot = $from
            ? EndingBalance::query()
                ->select('klien_ar_id', 'saldo_akhir_final')
                ->where('status', 'LOCKED')
                ->where('periode_akhir', '<', $from->toDateString())
                ->when($klienFilter, fn($q) => $q->where('klien_ar_id', $klienFilter))
                ->when($segmentTypes, fn($q) => $q->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes)))
                ->when($perusahaanFilter, fn($q) => $q->whereHas('klienAr', fn($q) => $q->where('perusahaan_id', $perusahaanFilter)))
                ->when($picArFilter, fn($q) => $q->whereHas('klienAr', fn($q) => $q->where('karyawan_ar_id', $picArFilter)))
                ->orderByDesc('periode_akhir')
                ->get()
                ->unique('klien_ar_id')          // ambil EB paling akhir per klien
                ->pluck('saldo_akhir_final', 'klien_ar_id')
            : collect();

        // Kumpulkan semua klien_ar_id yang relevan
        $klienIds = collect([
            $invoiceMasukQuery->keys(),
            $pembayaranQuery->keys(),
            $saldoAwalFallback->keys(),
            $ebSnapshot->keys(),
        ])->flatten()->unique()->values();

        if ($klienIds->isEmpty()) {
            return [
                'periode_awal'  => $from?->toDateString(),
                'periode_akhir' => $to?->toDateString(),
                'summary'       => ['saldo_awal' => 0, 'invoice_masuk' => 0, 'pembayaran' => 0, 'saldo_akhir' => 0],
                'rows'          => [],
            ];
        }

        $klienMap = KlienAr::with(['perusahaan', 'karyawanAr', 'resto'])
            ->whereIn('id', $klienIds)
            ->get()
            ->keyBy('id');

        $invoiceDetails = Invoice::query()
            ->whereIn('klien_ar_id', $klienIds)
            ->when($from, fn($q) => $q->whereDate('tanggal_invoice', '>=', $from->toDateString()))
            ->when($to, fn($q) => $q->whereDate('tanggal_invoice', '<=', $to->toDateString()))
            ->where(fn($q) => $this->approvedInvoiceScope($q))
            ->when($perusahaanFilter, fn($q) => $q->where('perusahaan_id', $perusahaanFilter))
            ->get([
                'id',
                'klien_ar_id',
                'no_invoice',
                'tanggal_invoice',
                'tanggal_jatuh_tempo',
                'subtotal',
                'total_tagihan',
                'total_pembayaran',
                'total_penyesuaian',
                'sisa_tagihan',
                'status',
            ])
            ->groupBy('klien_ar_id');

        $paymentDetails = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->join('tb_klien_ar', 'tb_invoice.klien_ar_id', '=', 'tb_klien_ar.id')
            ->whereIn('tb_invoice.klien_ar_id', $klienIds)
            ->when($from, fn($q) => $q->whereDate('tb_pembayaran_ar.tanggal_pembayaran', '>=', $from->toDateString()))
            ->when($to, fn($q) => $q->whereDate('tb_pembayaran_ar.tanggal_pembayaran', '<=', $to->toDateString()))
            ->when($perusahaanFilter, fn($q) => $q->where('tb_invoice.perusahaan_id', $perusahaanFilter))
            ->when($picArFilter, fn($q) => $q->where('tb_klien_ar.karyawan_ar_id', $picArFilter))
            ->get([
                'tb_pembayaran_ar.id',
                'tb_invoice.klien_ar_id',
                'tb_invoice.no_invoice',
                'tb_pembayaran_ar.invoice_id',
                'tb_pembayaran_ar.tanggal_pembayaran',
                'tb_pembayaran_ar.jumlah_pembayaran',
                'tb_pembayaran_ar.metode_pembayaran',
                'tb_pembayaran_ar.no_referensi',
                'tb_pembayaran_ar.keterangan',
            ])
            ->groupBy('klien_ar_id');

        $rows = $klienIds->map(function ($klienId) use (
            $invoiceMasukQuery, $pembayaranQuery,
            $saldoAwalFallback, $ebSnapshot, $klienMap,
            $invoiceDetails, $paymentDetails, $from
        ) {
            $klien        = $klienMap->get($klienId);
            $invoiceStat  = $invoiceMasukQuery->get($klienId);
            $paymentStat  = $pembayaranQuery->get($klienId);
            $invoiceMasuk = (float) ($invoiceStat?->invoice_masuk ?? 0);
            $pembayaran   = (float) ($paymentStat?->pembayaran ?? 0);

            // Gunakan EB snapshot jika ada, fallback ke compute all-time
            if (isset($ebSnapshot[$klienId])) {
                $saldoAwal = (float) $ebSnapshot[$klienId];
            } else {
                $saldoAwal = (float) ($saldoAwalFallback[$klienId] ?? 0);
            }

            $saldoAkhir  = $saldoAwal + $invoiceMasuk - $pembayaran;
            $details     = $this->buildLedgerDetails(
                $from?->toDateString(),
                max(0, $saldoAwal),
                $invoiceDetails->get($klienId, collect()),
                $paymentDetails->get($klienId, collect())
            );

            return [
                'klien_id'      => $klienId,
                'kode_klien'    => $klien?->kode_klien,
                'nama_klien'    => $klien?->nama_klien,
                'nama_resto'    => $klien?->resto?->nama_resto,
                'tipe_klien'    => $klien?->tipe_klien,
                'perusahaan'    => $klien?->perusahaan?->nama_singkatan_perusahaan,
                'pic_ar'        => $klien?->karyawanAr?->nama_karyawan,
                'saldo_awal'    => max(0, $saldoAwal),
                'invoice_masuk' => $invoiceMasuk,
                'pembayaran'    => $pembayaran,
                'saldo_akhir'   => max(0, $saldoAkhir),
                'jumlah_invoice_masuk' => (int) ($invoiceStat?->jumlah_invoice_masuk ?? 0),
                'jumlah_pembayaran'     => (int) ($paymentStat?->jumlah_pembayaran ?? 0),
                'details'       => $details,
            ];
        })->sortBy('nama_klien')->values()->all();

        $summary = [
            'saldo_awal'    => array_sum(array_column($rows, 'saldo_awal')),
            'invoice_masuk' => array_sum(array_column($rows, 'invoice_masuk')),
            'pembayaran'    => array_sum(array_column($rows, 'pembayaran')),
            'saldo_akhir'   => array_sum(array_column($rows, 'saldo_akhir')),
        ];

        return [
            'periode_awal'  => $from?->toDateString(),
            'periode_akhir' => $to?->toDateString(),
            'summary'       => $summary,
            'rows'          => $rows,
        ];
    }

    private function approvedInvoiceScope($query): void
    {
        $query
            ->where('is_opening_balance', false)
            ->orWhere(fn($q) => $q->where('is_opening_balance', true)->where('approval_status', 'APPROVED'));
    }

    private function buildLedgerDetails(?string $periodStart, float $saldoAwal, $invoices, $payments): array
    {
        $events = collect([[
            'tanggal'    => $periodStart,
            'tipe'       => 'SALDO_AWAL',
            'label'      => 'Saldo Awal',
            'no_dokumen' => 'Saldo Awal',
            'debit'      => 0,
            'kredit'     => 0,
            'keterangan' => 'Saldo awal periode',
            'sort_order' => 0,
            'id'         => 0,
        ]]);

        foreach ($invoices as $invoice) {
            $nominal = (float) ($invoice->subtotal == 0 ? $invoice->total_tagihan : $invoice->subtotal);
            $events->push([
                'tanggal'       => $invoice->tanggal_invoice?->toDateString(),
                'tipe'          => 'INVOICE',
                'label'         => 'Invoice',
                'invoice_id'    => $invoice->id,
                'no_dokumen'    => $invoice->no_invoice,
                'no_invoice'    => $invoice->no_invoice,
                'debit'         => $nominal,
                'kredit'        => 0,
                'tanggal_jatuh_tempo' => $invoice->tanggal_jatuh_tempo?->toDateString(),
                'status'        => $invoice->status,
                'sisa_tagihan'  => (float) $invoice->sisa_tagihan,
                'keterangan'    => 'Invoice masuk',
                'sort_order'    => 1,
                'id'            => $invoice->id,
            ]);
        }

        foreach ($payments as $payment) {
            $events->push([
                'tanggal'       => $payment->tanggal_pembayaran?->toDateString(),
                'tipe'          => 'PEMBAYARAN',
                'label'         => 'Pembayaran',
                'pembayaran_id' => $payment->id,
                'invoice_id'    => $payment->invoice_id,
                'no_dokumen'    => $payment->no_referensi ?: $payment->no_invoice,
                'no_invoice'    => $payment->no_invoice,
                'debit'         => 0,
                'kredit'        => (float) $payment->jumlah_pembayaran,
                'metode'        => $payment->metode_pembayaran,
                'keterangan'    => $payment->keterangan,
                'sort_order'    => 2,
                'id'            => $payment->id,
            ]);
        }

        $running = $saldoAwal;

        return $events
            ->sortBy(fn($event) => sprintf(
                '%s-%02d-%010d',
                $event['tanggal'] ?? $periodStart,
                $event['sort_order'],
                $event['id']
            ))
            ->values()
            ->map(function ($event) use (&$running) {
                if ($event['tipe'] !== 'SALDO_AWAL') {
                    $running = max(0, $running + (float) $event['debit'] - (float) $event['kredit']);
                }

                unset($event['sort_order']);
                $event['saldo'] = $running;

                return $event;
            })
            ->values()
            ->all();
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
