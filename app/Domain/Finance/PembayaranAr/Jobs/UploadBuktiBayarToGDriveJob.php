<?php

namespace App\Domain\Finance\PembayaranAr\Jobs;

use App\Models\PembayaranAr;
use App\Support\Helpers\GoogleDriveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadBuktiBayarToGDriveJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries  = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly int    $pembayaranArId,
        private readonly string $tempPath,
        private readonly string $fileName,
        private readonly string $mimeType,
        private readonly int    $fileSize,
    ) {}

    public function handle(GoogleDriveService $driveService): void
    {
        $pembayaran = PembayaranAr::with(['invoice.klienAr'])->find($this->pembayaranArId);

        if (!$pembayaran) {
            Log::warning('UploadBuktiBayarToGDriveJob: pembayaran tidak ditemukan', ['id' => $this->pembayaranArId]);
            $this->cleanup();
            return;
        }

        if (!Storage::exists($this->tempPath)) {
            Log::warning('UploadBuktiBayarToGDriveJob: file temp tidak ditemukan', [
                'pembayaran_id' => $this->pembayaranArId,
                'temp_path'     => $this->tempPath,
            ]);
            return;
        }

        try {
            $fileContent = Storage::get($this->tempPath);
            $clientName  = $pembayaran->invoice?->klienAr?->nama_klien ?? 'Umum';
            $rootId      = config('services.google_drive.root_folder_id');

            // Cari/buat folder klien, lalu subfolder "Bukti Pembayaran" di dalamnya
            $clientFolderId = $driveService->findOrCreateClientFolder($rootId, $clientName);
            $buktiFolderId  = $driveService->findOrCreateClientFolder($clientFolderId, 'Bukti Pembayaran');

            $fileId = $driveService->uploadFile($buktiFolderId, $this->fileName, $fileContent, $this->mimeType);

            $pembayaran->updateQuietly([
                'bukti_gdrive_file_id'  => $fileId,
                'bukti_gdrive_folder_id' => $buktiFolderId,
                'bukti_file_name'       => $this->fileName,
                'bukti_file_size'       => $this->fileSize,
                'bukti_mime_type'       => $this->mimeType,
                'bukti_uploaded_at'     => now(),
            ]);

            Log::info('UploadBuktiBayarToGDriveJob: upload berhasil', [
                'pembayaran_id' => $pembayaran->id,
                'file_id'       => $fileId,
            ]);
        } catch (Throwable $e) {
            Log::error('UploadBuktiBayarToGDriveJob: upload gagal', [
                'pembayaran_id' => $this->pembayaranArId,
                'error'         => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        if (Storage::exists($this->tempPath)) {
            Storage::delete($this->tempPath);
        }
    }
}
