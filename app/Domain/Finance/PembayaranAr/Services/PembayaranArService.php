<?php

namespace App\Domain\Finance\PembayaranAr\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use App\Models\PembayaranArLog;

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

        $pembayaran = PembayaranAr::create([
            'invoice_id'         => $invoice->id,
            'tanggal_pembayaran' => $data['tanggal_pembayaran'],
            'jumlah_pembayaran'  => $data['jumlah_pembayaran'],
            'metode_pembayaran'  => $data['metode_pembayaran'],
            'no_referensi'       => $data['no_referensi'] ?? null,
            'keterangan'         => $data['keterangan'] ?? null,
            'created_by'         => auth()->id(),
        ]);

        PembayaranArLog::create([
            'pembayaran_ar_id' => $pembayaran->id,
            'aksi'             => 'DIBUAT',
            'actor_id'         => auth()->id(),
            'data_sesudah'     => $pembayaran->toArray(),
        ]);

        $this->invoiceService->recalculate($invoice->fresh());

        return $pembayaran->load('createdBy');
    }

    public function delete(PembayaranAr $pembayaran): void
    {
        PembayaranArLog::create([
            'pembayaran_ar_id' => $pembayaran->id,
            'aksi'             => 'DIHAPUS',
            'actor_id'         => auth()->id(),
            'data_sebelum'     => $pembayaran->toArray(),
        ]);

        $invoice = $pembayaran->invoice;
        $pembayaran->delete();
        $this->invoiceService->recalculate($invoice->fresh());
    }

    public function cekDuplikatReferensi(string $noRef): ?array
    {
        $existing = PembayaranAr::with(['invoice.karyawan', 'invoice.klienAr'])
            ->where('no_referensi', $noRef)
            ->first();

        if (!$existing) {
            return null;
        }

        return [
            'pembayaran_id'      => $existing->id,
            'no_invoice'         => $existing->invoice?->no_invoice,
            'klien'              => $existing->invoice?->klienAr?->nama_klien,
            'pic'                => $existing->invoice?->karyawan?->nama_karyawan,
            'tanggal_pembayaran' => $existing->tanggal_pembayaran?->format('Y-m-d'),
            'jumlah_pembayaran'  => (float) $existing->jumlah_pembayaran,
            'metode_pembayaran'  => $existing->metode_pembayaran,
        ];
    }
}
