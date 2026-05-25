<?php

namespace App\Domain\Finance\PembayaranAr\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAr\Jobs\UploadBuktiBayarToGDriveJob;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use App\Models\PembayaranArLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PembayaranArService
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function create(Invoice $invoice, array $data, ?UploadedFile $buktiBayar = null): PembayaranAr
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

        if ($buktiBayar) {
            $this->dispatchBuktiUpload($pembayaran, $buktiBayar);
        }

        return $pembayaran->load('createdBy');
    }

    private function dispatchBuktiUpload(PembayaranAr $pembayaran, UploadedFile $file): void
    {
        $ext      = $file->getClientOriginalExtension();
        $fileName = 'Pembayaran-' . $pembayaran->id . '-' . now()->format('Ymd') . '.' . $ext;
        $tempPath = 'temp/bukti/' . $fileName;

        Storage::put($tempPath, file_get_contents($file->getRealPath()));

        UploadBuktiBayarToGDriveJob::dispatch(
            $pembayaran->id,
            $tempPath,
            $fileName,
            $file->getMimeType() ?? $file->getClientMimeType(),
            $file->getSize(),
        );
    }

    public function delete(PembayaranAr $pembayaran): void
    {
        // Kumpulkan alokasi anak DAN invoice tujuannya SEBELUM FK nullOnDelete berjalan.
        // Setelah $pembayaran->delete(), sumber_pembayaran_ar_id di-NULL sehingga
        // query WHERE sumber_pembayaran_ar_id = X tidak akan menemukan records lagi.
        $childAllocations = $pembayaran->alokasiKelebihan()->with('invoice')->get();
        $affectedInvoices = $childAllocations->map->invoice->filter()->unique('id')->values();

        PembayaranArLog::create([
            'pembayaran_ar_id' => $pembayaran->id,
            'aksi'             => 'DIHAPUS',
            'actor_id'         => auth()->id(),
            'data_sebelum'     => $pembayaran->toArray(),
        ]);

        foreach ($childAllocations as $alloc) {
            PembayaranArLog::create([
                'pembayaran_ar_id' => $alloc->id,
                'aksi'             => 'DIHAPUS',
                'actor_id'         => auth()->id(),
                'data_sebelum'     => $alloc->toArray(),
            ]);
        }

        $pembayaran->alokasiKelebihan()->delete();

        $linkedStatementIds = BankStatementDetail::where('pembayaran_ar_id', $pembayaran->id)
            ->pluck('bank_statement_id')
            ->unique()
            ->all();
        BankStatementDetail::where('pembayaran_ar_id', $pembayaran->id)
            ->update(['pembayaran_ar_id' => null, 'status_cocok' => 'UNMATCHED']);

        $invoice = $pembayaran->invoice;
        $pembayaran->delete();

        foreach ($linkedStatementIds as $statementId) {
            $matched   = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'MATCHED')->count();
            $unmatched = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'UNMATCHED')->count();
            BankStatement::where('id', $statementId)->update([
                'jumlah_matched'   => $matched,
                'jumlah_unmatched' => $unmatched,
            ]);
        }

        $this->invoiceService->recalculate($invoice->fresh());

        foreach ($affectedInvoices as $targetInvoice) {
            if ($targetInvoice->id !== $invoice->id) {
                $this->invoiceService->recalculate($targetInvoice->fresh());
            }
        }
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
            'tanggal_pembayaran' => $existing->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'  => (float) $existing->jumlah_pembayaran,
            'metode_pembayaran'  => $existing->metode_pembayaran,
        ];
    }
}
