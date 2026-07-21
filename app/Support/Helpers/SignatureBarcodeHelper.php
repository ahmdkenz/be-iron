<?php

namespace App\Support\Helpers;

use App\Models\EndingBalanceApKoreksi;
use App\Models\EndingBalanceKoreksi;
use App\Models\Invoice;
use App\Models\PembayaranAp;
use App\Models\TagihanAp;
use Carbon\Carbon;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

class SignatureBarcodeHelper
{
    public static function generateDataUri(?string $payload, int $size = 250): ?string
    {
        if (!$payload) {
            return null;
        }

        try {
            $qrCode = new QrCode(data: $payload, size: $size, margin: 4);
            $result = (new SvgWriter())->write($qrCode);

            return 'data:image/svg+xml;base64,' . base64_encode($result->getString());
        } catch (Throwable) {
            return null;
        }
    }

    public static function buildPreparedVerificationUrl(Invoice $invoice): ?string
    {
        if (!$invoice->prepared_token) {
            return null;
        }

        return route('verify.prepared', ['token' => $invoice->prepared_token]);
    }

    public static function buildApprovedVerificationUrl(Invoice $invoice): ?string
    {
        if (!$invoice->approved_token) {
            return null;
        }

        return route('verify.approved', ['token' => $invoice->approved_token]);
    }

    public static function buildObPreparedPayload(Invoice $invoice, string $preparedByName): string
    {
        $periode = Carbon::parse($invoice->tanggal_invoice)->format('d-m-Y');
        $grandTotal = 'Rp ' . number_format((float) $invoice->total_tagihan, 0, ',', '.');
        $sisaBayar  = 'Rp ' . number_format((float) $invoice->sisa_tagihan, 0, ',', '.');

        $lines = [
            "Diajukan Oleh: {$preparedByName}",
            "No Invoice: {$invoice->no_invoice}",
            "Periode: {$periode}",
        ];

        $obDetails = $invoice->openingBalanceDetails ?? collect();
        foreach ($obDetails as $detail) {
            foreach ($detail->items as $item) {
                $qty = rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.');
                $lines[] = "Nama Barang: {$item->nama_barang} | QTY: {$qty} | Satuan: {$item->satuan}";
            }
        }

        $lines[] = "Grand Total: {$grandTotal}";
        $lines[] = "Sisa Bayar: {$sisaBayar}";
        $lines[] = "Status: Di Ajukan";

        return implode("\n", $lines);
    }

    public static function buildInvoicePreparedPayload(Invoice $invoice, string $preparedByName): string
    {
        $periode    = Carbon::parse($invoice->tanggal_invoice)->format('d-m-Y');
        $grandTotal = 'Rp ' . number_format((float) $invoice->total_tagihan, 0, ',', '.');
        $sisaBayar  = 'Rp ' . number_format((float) $invoice->sisa_tagihan, 0, ',', '.');

        $lines = [
            "Disiapkan Oleh: {$preparedByName}",
            "No Invoice: {$invoice->no_invoice}",
            "Periode: {$periode}",
            "Grand Total: {$grandTotal}",
            "Sisa Bayar: {$sisaBayar}",
            "Status: Di Ajukan",
        ];

        return implode("\n", $lines);
    }

    public static function buildTagihanApPreparedPayload(TagihanAp $tagihan, string $preparedByName): string
    {
        $tanggal      = Carbon::parse($tagihan->tanggal_tagihan)->format('d-m-Y');
        $totalTagihan = 'Rp ' . number_format((float) $tagihan->total_tagihan, 0, ',', '.');
        $sisaTagihan  = 'Rp ' . number_format((float) $tagihan->sisa_tagihan, 0, ',', '.');

        $lines = [
            "Disiapkan Oleh: {$preparedByName}",
            "No Tagihan: {$tagihan->no_tagihan}",
            "Vendor: " . ($tagihan->vendorAp?->nama_vendor ?? '-'),
            "Tanggal: {$tanggal}",
            "Total Tagihan: {$totalTagihan}",
            "Sisa Tagihan: {$sisaTagihan}",
            "Status: {$tagihan->status}",
        ];

        return implode("\n", $lines);
    }

    public static function buildKoreksiPreparedPayload(EndingBalanceKoreksi $k, string $preparedByName): string
    {
        $tanggal      = $k->manager_actioned_at ?? $k->submitted_at;
        $tglFormatted = $tanggal ? Carbon::parse($tanggal)->format('d-m-Y') : '-';
        $nilai        = 'Rp ' . number_format(abs((float) $k->nilai_koreksi), 0, ',', '.');

        $lines = [
            "Disiapkan Oleh: {$preparedByName}",
            "No Dokumen: {$k->no_dokumen}",
            "No Invoice: " . ($k->invoice?->no_invoice ?? '-'),
            "Nilai: {$nilai}",
            "Tanggal: {$tglFormatted}",
            "Status: {$k->status}",
        ];

        return implode("\n", $lines);
    }

    public static function buildKoreksiApprovedPayload(EndingBalanceKoreksi $k, string $approvedByName): string
    {
        $tanggal      = $k->manager_actioned_at ?? $k->submitted_at;
        $tglFormatted = $tanggal ? Carbon::parse($tanggal)->format('d-m-Y') : '-';
        $nilai        = 'Rp ' . number_format(abs((float) $k->nilai_koreksi), 0, ',', '.');

        $lines = [
            "Disetujui Oleh: {$approvedByName}",
            "No Dokumen: {$k->no_dokumen}",
            "No Invoice: " . ($k->invoice?->no_invoice ?? '-'),
            "Nilai: {$nilai}",
            "Tanggal: {$tglFormatted}",
            "Status: {$k->status}",
        ];

        return implode("\n", $lines);
    }

    public static function buildKoreksiApPreparedPayload(EndingBalanceApKoreksi $k, string $preparedByName): string
    {
        $tanggal      = $k->approved_at ?? $k->submitted_at;
        $tglFormatted = $tanggal ? Carbon::parse($tanggal)->format('d-m-Y') : '-';
        $nilai        = 'Rp ' . number_format(abs((float) $k->nilai_koreksi), 0, ',', '.');

        $lines = [
            "Disiapkan Oleh: {$preparedByName}",
            "No Dokumen: {$k->no_dokumen}",
            "No Tagihan: " . ($k->tagihanAp?->no_tagihan ?? '-'),
            "Nilai: {$nilai}",
            "Tanggal: {$tglFormatted}",
            "Status: {$k->status}",
        ];

        return implode("\n", $lines);
    }

    public static function buildKoreksiApApprovedPayload(EndingBalanceApKoreksi $k, string $approvedByName): string
    {
        $tanggal      = $k->approved_at ?? $k->submitted_at;
        $tglFormatted = $tanggal ? Carbon::parse($tanggal)->format('d-m-Y') : '-';
        $nilai        = 'Rp ' . number_format(abs((float) $k->nilai_koreksi), 0, ',', '.');

        $lines = [
            "Disetujui Oleh: {$approvedByName}",
            "No Dokumen: {$k->no_dokumen}",
            "No Tagihan: " . ($k->tagihanAp?->no_tagihan ?? '-'),
            "Nilai: {$nilai}",
            "Tanggal: {$tglFormatted}",
            "Status: {$k->status}",
        ];

        return implode("\n", $lines);
    }

    public static function buildOpeningBalanceApPreparedPayload(TagihanAp $tagihan, string $preparedByName): string
    {
        $periode      = Carbon::parse($tagihan->tanggal_tagihan)->format('d-m-Y');
        $totalTagihan = 'Rp ' . number_format((float) $tagihan->total_tagihan, 0, ',', '.');
        $sisaTagihan  = 'Rp ' . number_format((float) $tagihan->sisa_tagihan, 0, ',', '.');

        $lines = [
            "Diajukan Oleh: {$preparedByName}",
            "No Opening Balance: {$tagihan->no_tagihan}",
            "Vendor: " . ($tagihan->vendorAp?->nama_vendor ?? '-'),
            "Periode: {$periode}",
        ];

        $obDetails = $tagihan->openingBalanceApDetails ?? collect();
        foreach ($obDetails as $detail) {
            foreach ($detail->items as $item) {
                $qty = rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.');
                $lines[] = "Nama Barang: {$item->nama_barang} | QTY: {$qty} | Satuan: {$item->satuan}";
            }
        }

        $lines[] = "Total Saldo Awal: {$totalTagihan}";
        $lines[] = "Sisa Hutang: {$sisaTagihan}";
        $lines[] = "Status: Di Ajukan";

        return implode("\n", $lines);
    }

    public static function buildPembayaranApPreparedPayload(
        PembayaranAp $pembayaran,
        string $preparedByName,
        string $noVoucher,
        int $tagihanCount,
        int $vendorCount,
    ): string {
        $tanggal      = Carbon::parse($pembayaran->tanggal_pembayaran)->format('d-m-Y');
        $totalBayar   = 'Rp ' . number_format((float) $pembayaran->jumlah_pembayaran, 0, ',', '.');
        $kategori     = match ($pembayaran->kategori_voucher) {
            'BB'    => 'Bahan Baku',
            'NBB'   => 'Non Bahan Baku',
            default => 'Belum Dikategorikan',
        };

        $lines = [
            "Dibuat Oleh: {$preparedByName}",
            "No Payment Voucher: {$noVoucher}",
            "Tanggal: {$tanggal}",
            "Kategori: {$kategori}",
            "Total Pembayaran: {$totalBayar}",
            "Jumlah Tagihan: {$tagihanCount}",
            "Jumlah Vendor: {$vendorCount}",
            "Status: Dibuat",
        ];

        return implode("\n", $lines);
    }

    public static function buildOpeningBalanceApApprovedPayload(TagihanAp $tagihan, string $approvedByName): string
    {
        $tglFormatted = $tagihan->approved_at ? Carbon::parse($tagihan->approved_at)->format('d-m-Y') : '-';
        $totalTagihan = 'Rp ' . number_format((float) $tagihan->total_tagihan, 0, ',', '.');

        $lines = [
            "Disetujui Oleh: {$approvedByName}",
            "No Opening Balance: {$tagihan->no_tagihan}",
            "Vendor: " . ($tagihan->vendorAp?->nama_vendor ?? '-'),
            "Total Saldo Awal: {$totalTagihan}",
            "Tanggal Disetujui: {$tglFormatted}",
            "Status: {$tagihan->approval_status}",
        ];

        return implode("\n", $lines);
    }
}
