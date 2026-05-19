<?php

namespace App\Console\Commands;

use App\Support\Helpers\GoogleDriveService;
use Illuminate\Console\Command;

class GDriveTestCommand extends Command
{
    protected $signature   = 'gdrive:test';
    protected $description = 'Test koneksi dan autentikasi Google Drive';

    public function handle(GoogleDriveService $drive): void
    {
        $rootFolderId = config('services.google_drive.root_folder_id');

        $this->newLine();
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   Google Drive — Connection Test             ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->newLine();

        // 1. Coba buat folder test
        $this->line('1. Membuat folder test di Google Drive...');
        try {
            $folderId = $drive->findOrCreateClientFolder($rootFolderId, '__IRON_CONNECTION_TEST__');
            $this->info("   ✓ Folder berhasil — ID: {$folderId}");
        } catch (\Throwable $e) {
            $this->error('   ✗ GAGAL membuat folder: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Pastikan GOOGLE_REFRESH_TOKEN sudah diisi di .env dan jalankan php artisan config:clear');
            return;
        }

        // 2. Upload file test
        $this->line('2. Mengupload file test...');
        $fileId = null;
        try {
            $fileId = $drive->uploadPdf($folderId, 'iron-connection-test.pdf', '%PDF-1.4 IRON test');
            $this->info("   ✓ File berhasil diupload — ID: {$fileId}");
        } catch (\Throwable $e) {
            $this->error('   ✗ GAGAL upload file: ' . $e->getMessage());
        }

        // 3. Cleanup
        $this->line('3. Membersihkan file & folder test...');
        try {
            if ($fileId) {
                $drive->deleteItem($fileId);
            }
            $drive->deleteItem($folderId);
            $this->info('   ✓ Cleanup selesai');
        } catch (\Throwable $e) {
            $this->warn('   Folder __IRON_CONNECTION_TEST__ di Google Drive perlu dihapus manual.');
        }

        $this->newLine();
        if ($fileId) {
            $this->info('✓ KONEKSI GOOGLE DRIVE BERHASIL — siap digunakan!');
        } else {
            $this->warn('Folder bisa dibuat tapi upload file gagal. Cek log untuk detail.');
        }
        $this->newLine();
    }
}
