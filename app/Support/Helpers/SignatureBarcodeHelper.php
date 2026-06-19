<?php

namespace App\Support\Helpers;

use App\Models\Invoice;
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
}
