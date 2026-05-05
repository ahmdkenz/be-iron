<?php

namespace App\Support\Helpers;

use App\Models\Invoice;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

class SignatureBarcodeHelper
{
    public static function generateDataUri(?string $payload, int $size = 120): ?string
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
}
