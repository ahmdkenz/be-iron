<?php

namespace App\Support\Helpers;

use App\Models\Invoice;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

class SignatureBarcodeHelper
{
    public static function generateDataUri(?string $payload): ?string
    {
        if (!$payload) {
            return null;
        }

        try {
            $qrCode = new QrCode(data: $payload, size: 120, margin: 4);
            $result = (new SvgWriter())->write($qrCode);

            return 'data:image/svg+xml;base64,' . base64_encode($result->getString());
        } catch (Throwable) {
            return null;
        }
    }

    public static function buildPreparedOpeningBalancePayload(Invoice $invoice): ?string
    {
        if (!$invoice->is_opening_balance) {
            return null;
        }

        $actorId = $invoice->submitted_by ?: $invoice->created_by;
        $signedAt = $invoice->submitted_at ?: $invoice->created_at;

        if (!$actorId || !$signedAt) {
            return null;
        }

        return sprintf(
            'OB-%d-SUB-%d-%s',
            $invoice->id,
            $actorId,
            $signedAt->format('YmdHis')
        );
    }

    public static function buildApprovedOpeningBalancePayload(Invoice $invoice): ?string
    {
        if (
            !$invoice->is_opening_balance
            || $invoice->approval_status !== 'APPROVED'
            || !$invoice->approved_by
            || !$invoice->approved_at
        ) {
            return null;
        }

        return sprintf(
            'OB-%d-APR-%d-%s',
            $invoice->id,
            $invoice->approved_by,
            $invoice->approved_at->format('YmdHis')
        );
    }
}
