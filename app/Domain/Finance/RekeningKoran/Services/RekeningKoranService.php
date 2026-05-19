<?php

namespace App\Domain\Finance\RekeningKoran\Services;

use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use Carbon\Carbon;

class RekeningKoranService
{
    public function getReport(array $filters = []): array
    {
        $klienId = $filters['klien_ar_id'];

        $from = isset($filters['periode_awal'])
            ? Carbon::parse($filters['periode_awal'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = isset($filters['periode_akhir'])
            ? Carbon::parse($filters['periode_akhir'])->endOfDay()
            : Carbon::now()->endOfMonth();

        $klien = KlienAr::with('perusahaan')->findOrFail($klienId);

        // Saldo awal = tagihan sebelum periode − pembayaran sebelum periode
        $tagihanSebelum = (float) Invoice::query()
            ->where('klien_ar_id', $klienId)
            ->where('tanggal_invoice', '<', $from->toDateString())
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->sum('total_tagihan');

        $bayarSebelum = (float) PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->where('tb_invoice.klien_ar_id', $klienId)
            ->where('tb_pembayaran_ar.tanggal_pembayaran', '<', $from->toDateString())
            ->sum('tb_pembayaran_ar.jumlah_pembayaran');

        $saldoAwal = max(0, $tagihanSebelum - $bayarSebelum);

        // Invoice dalam periode
        $invoices = Invoice::query()
            ->where('klien_ar_id', $klienId)
            ->whereBetween('tanggal_invoice', [$from->toDateString(), $to->toDateString()])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->orderBy('tanggal_invoice')
            ->orderBy('created_at')
            ->get(['id', 'no_invoice', 'tanggal_invoice', 'total_tagihan', 'is_opening_balance']);

        // Pembayaran dalam periode
        $pembayaran = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->where('tb_invoice.klien_ar_id', $klienId)
            ->whereBetween('tb_pembayaran_ar.tanggal_pembayaran', [$from->toDateString(), $to->toDateString()])
            ->orderBy('tb_pembayaran_ar.tanggal_pembayaran')
            ->orderBy('tb_pembayaran_ar.created_at')
            ->get([
                'tb_pembayaran_ar.id',
                'tb_pembayaran_ar.tanggal_pembayaran',
                'tb_pembayaran_ar.jumlah_pembayaran',
                'tb_pembayaran_ar.metode_pembayaran',
                'tb_pembayaran_ar.no_referensi',
                'tb_pembayaran_ar.keterangan',
            ]);

        // Gabungkan dan urutkan kronologis
        $rows = collect();

        foreach ($invoices as $inv) {
            $rows->push([
                'tanggal'    => $inv->tanggal_invoice instanceof Carbon
                    ? $inv->tanggal_invoice->toDateString()
                    : (string) $inv->tanggal_invoice,
                'tipe'       => 'INVOICE',
                'no_dokumen' => $inv->no_invoice,
                'keterangan' => $inv->is_opening_balance ? 'Saldo Awal (Opening Balance)' : 'Invoice Tagihan',
                'debit'      => (float) $inv->total_tagihan,
                'kredit'     => 0.0,
                'sort_date'  => $inv->tanggal_invoice,
                'sort_type'  => 1, // invoice comes before payment on same day
            ]);
        }

        foreach ($pembayaran as $pay) {
            $rows->push([
                'tanggal'    => $pay->tanggal_pembayaran instanceof Carbon
                    ? $pay->tanggal_pembayaran->toDateString()
                    : (string) $pay->tanggal_pembayaran,
                'tipe'       => 'PEMBAYARAN',
                'no_dokumen' => $pay->no_referensi ?: '-',
                'keterangan' => trim(($pay->metode_pembayaran ?? '') . ($pay->keterangan ? (' — ' . $pay->keterangan) : '')),
                'debit'      => 0.0,
                'kredit'     => (float) $pay->jumlah_pembayaran,
                'sort_date'  => $pay->tanggal_pembayaran,
                'sort_type'  => 2, // payment comes after invoice on same day
            ]);
        }

        $sorted = $rows->sortBy([
            fn($a, $b) => strcmp((string) $a['sort_date'], (string) $b['sort_date']),
            fn($a, $b) => $a['sort_type'] <=> $b['sort_type'],
        ])->values();

        // Hitung saldo berjalan
        $saldo = $saldoAwal;
        $result = [];
        foreach ($sorted as $row) {
            $saldo += $row['debit'] - $row['kredit'];
            $result[] = [
                'tanggal'    => $row['tanggal'],
                'tipe'       => $row['tipe'],
                'no_dokumen' => $row['no_dokumen'],
                'keterangan' => $row['keterangan'],
                'debit'      => $row['debit'],
                'kredit'     => $row['kredit'],
                'saldo'      => max(0, $saldo),
            ];
        }

        $totalDebit  = array_sum(array_column($result, 'debit'));
        $totalKredit = array_sum(array_column($result, 'kredit'));
        $saldoAkhir  = max(0, $saldoAwal + $totalDebit - $totalKredit);

        return [
            'klien' => [
                'id'          => $klien->id,
                'kode_klien'  => $klien->kode_klien,
                'nama_klien'  => $klien->nama_klien,
                'perusahaan'  => $klien->perusahaan?->nama_singkatan_perusahaan,
            ],
            'periode_awal'  => $from->toDateString(),
            'periode_akhir' => $to->toDateString(),
            'saldo_awal'    => $saldoAwal,
            'total_debit'   => $totalDebit,
            'total_kredit'  => $totalKredit,
            'saldo_akhir'   => $saldoAkhir,
            'rows'          => $result,
        ];
    }
}
