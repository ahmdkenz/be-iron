<?php

namespace App\Domain\Finance\PembayaranAr\Services;

use App\Domain\Finance\Invoice\Jobs\UploadInvoiceToGDriveJob;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAr\Jobs\UploadBuktiBayarToGDriveJob;
use App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use App\Models\PembayaranArLog;
use App\Models\PendapatanDiMuka;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PembayaranArService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly PendapatanDiMukaService $pdmService,
    ) {}

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
            'dibuat_dari_rekonsiliasi' => $data['dibuat_dari_rekonsiliasi'] ?? false,
            'created_by'         => auth()->id(),
        ]);

        PembayaranArLog::create([
            'pembayaran_ar_id' => $pembayaran->id,
            'aksi'             => 'DIBUAT',
            'actor_id'         => auth()->id(),
            'data_sesudah'     => $pembayaran->toArray(),
        ]);

        $this->invoiceService->recalculate($invoice->fresh());

        // Pelunasan OB → lunaskan invoice reguler periode sebelumnya yang dipilih user.
        if ($invoice->is_opening_balance && !empty($data['settle_original_invoice_ids'])) {
            $this->invoiceService->settleOriginalsFromOpeningBalance(
                $invoice->fresh(),
                $pembayaran,
                $data['settle_original_invoice_ids'],
            );
        }

        UploadInvoiceToGDriveJob::dispatch($invoice->id);

        if ($buktiBayar) {
            try {
                $this->dispatchBuktiUpload($pembayaran, $buktiBayar);
            } catch (\Throwable $e) {
                Log::error('PembayaranArService: gagal upload bukti bayar ke GDrive', [
                    'pembayaran_id' => $pembayaran->id,
                    'error'         => $e->getMessage(),
                ]);
            }
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
        $pdm = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $pembayaran->id)->first();

        abort_if(
            $pdm && $pdm->status === 'TERPAKAI',
            422,
            'Tidak dapat menghapus pembayaran ini karena Pendapatan di Muka sudah digunakan untuk melunasi invoice. Batalkan penggunaan PDM terlebih dahulu.'
        );

        // Kumpulkan alokasi anak DAN invoice tujuannya SEBELUM FK nullOnDelete berjalan.
        // Setelah $pembayaran->delete(), sumber_pembayaran_ar_id di-NULL sehingga
        // query WHERE sumber_pembayaran_ar_id = X tidak akan menemukan records lagi.
        $childAllocations = $pembayaran->alokasiKelebihan()->with('invoice')->get();
        $affectedInvoices = $childAllocations->map->invoice->filter()->unique('id')->values();

        $invoiceId = $pembayaran->invoice_id;
        $sumberId  = $pembayaran->sumber_pembayaran_ar_id;

        DB::transaction(function () use ($pembayaran, $childAllocations, $affectedInvoices, $invoiceId, $pdm, $sumberId) {
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

            if ($pdm) {
                $pdm->delete();
            }

            $linkedStatementIds = BankStatementDetail::where('pembayaran_ar_id', $pembayaran->id)
                ->pluck('bank_statement_id')
                ->unique()
                ->all();
            BankStatementDetail::where('pembayaran_ar_id', $pembayaran->id)
                ->update(['pembayaran_ar_id' => null, 'status_cocok' => 'UNMATCHED']);

            $pembayaran->delete();

            if ($sumberId) {
                $parentPdm = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $sumberId)->first();
                if ($parentPdm && $parentPdm->status !== 'DIBATALKAN') {
                    $this->pdmService->recalculate($parentPdm->fresh());
                }
            }

            foreach ($linkedStatementIds as $statementId) {
                $matched   = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'MATCHED')->count();
                $unmatched = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'UNMATCHED')->count();
                BankStatement::where('id', $statementId)->update([
                    'jumlah_matched'   => $matched,
                    'jumlah_unmatched' => $unmatched,
                ]);
            }

            $invoice = Invoice::find($invoiceId);
            $this->invoiceService->recalculate($invoice);

            if ($invoice) {
                UploadInvoiceToGDriveJob::dispatch($invoice->id);
            }

            foreach ($affectedInvoices as $targetInvoice) {
                if ($targetInvoice->id !== $invoiceId) {
                    $this->invoiceService->recalculate($targetInvoice->fresh());
                    UploadInvoiceToGDriveJob::dispatch($targetInvoice->id);
                }
            }
        });
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
