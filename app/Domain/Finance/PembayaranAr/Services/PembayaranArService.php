<?php

namespace App\Domain\Finance\PembayaranAr\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Invoice;
use App\Models\PembayaranAr;

class PembayaranArService
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function create(Invoice $invoice, array $data): PembayaranAr
    {
        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, pembayaran belum dapat dicatat'
        );

        abort_if(
            $invoice->status === 'LUNAS',
            422,
            'Invoice ini sudah berstatus LUNAS, tidak dapat menambah pembayaran'
        );

        abort_if(
            (float) $data['jumlah_pembayaran'] > (float) $invoice->sisa_tagihan,
            422,
            'Jumlah pembayaran melebihi sisa tagihan (Rp ' . number_format($invoice->sisa_tagihan, 0, ',', '.') . ')'
        );

        $pembayaran = PembayaranAr::create([
            'invoice_id'         => $invoice->id,
            'tanggal_pembayaran' => $data['tanggal_pembayaran'],
            'jumlah_pembayaran'  => $data['jumlah_pembayaran'],
            'metode_pembayaran'  => $data['metode_pembayaran'],
            'no_referensi'       => $data['no_referensi'] ?? null,
            'keterangan'         => $data['keterangan'] ?? null,
            'created_by'         => auth()->id(),
        ]);

        $this->invoiceService->recalculate($invoice->fresh());

        return $pembayaran->load('createdBy');
    }

    public function delete(PembayaranAr $pembayaran): void
    {
        $invoice = $pembayaran->invoice;
        $pembayaran->delete();
        $this->invoiceService->recalculate($invoice->fresh());
    }
}
