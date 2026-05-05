<?php

namespace App\Domain\Finance\Verification\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;

class VerificationController extends Controller
{
    public function prepared(string $token): View
    {
        $invoice = Invoice::with([
            'klienAr.karyawanAr',
            'perusahaan',
            'submittedBy.karyawan',
            'createdBy.karyawan',
        ])->where('prepared_token', $token)->firstOrFail();

        return view('finance.verify-document', [
            'invoice' => $invoice,
            'context' => 'prepared',
        ]);
    }

    public function approved(string $token): View
    {
        $invoice = Invoice::with([
            'klienAr',
            'perusahaan',
            'approvedBy.karyawan',
        ])->where('approved_token', $token)->firstOrFail();

        return view('finance.verify-document', [
            'invoice' => $invoice,
            'context' => 'approved',
        ]);
    }
}
