<?php

namespace App\Support\Helpers;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    private GoogleDrive $drive;

    public function __construct()
    {
        $client = new GoogleClient();
        $client->setClientId(config('services.google_drive.client_id'));
        $client->setClientSecret(config('services.google_drive.client_secret'));
        $client->setRedirectUri(config('services.google_drive.redirect_uri'));
        $client->setAccessType('offline');
        $client->addScope(GoogleDrive::DRIVE);

        // Gunakan refresh token untuk mendapatkan access token otomatis
        $client->fetchAccessTokenWithRefreshToken(config('services.google_drive.refresh_token'));

        $this->drive = new GoogleDrive($client);
    }

    /**
     * Cari folder klien di dalam root folder, buat jika belum ada.
     * Return: Google Drive folder ID.
     */
    public function findOrCreateClientFolder(string $rootFolderId, string $clientName): string
    {
        $safeName = $this->sanitizeFolderName($clientName);

        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and name='%s' and '%s' in parents and trashed=false",
            addslashes($safeName),
            $rootFolderId
        );

        $results = $this->drive->files->listFiles([
            'q'      => $query,
            'fields' => 'files(id, name)',
        ]);

        if (count($results->getFiles()) > 0) {
            return $results->getFiles()[0]->getId();
        }

        $folderMeta = new DriveFile([
            'name'     => $safeName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$rootFolderId],
        ]);

        $folder = $this->drive->files->create($folderMeta, ['fields' => 'id']);

        Log::info('GoogleDriveService: folder klien dibuat', [
            'nama_klien' => $safeName,
            'folder_id'  => $folder->getId(),
        ]);

        return $folder->getId();
    }

    /**
     * Upload file PDF ke folder yang ditentukan.
     * Return: Google Drive file ID.
     */
    public function uploadPdf(string $folderId, string $fileName, string $pdfContent): string
    {
        return $this->uploadFile($folderId, $fileName, $pdfContent, 'application/pdf');
    }

    /**
     * Update konten file PDF yang sudah ada di Google Drive (file ID tetap sama).
     */
    public function updatePdf(string $fileId, string $pdfContent): void
    {
        $this->drive->files->update($fileId, new DriveFile(), [
            'data'       => $pdfContent,
            'mimeType'   => 'application/pdf',
            'uploadType' => 'multipart',
        ]);
    }

    /**
     * Upload file dengan MIME type apapun ke folder yang ditentukan.
     * Return: Google Drive file ID.
     */
    public function uploadFile(string $folderId, string $fileName, string $fileContent, string $mimeType): string
    {
        $fileMeta = new DriveFile([
            'name'    => $fileName,
            'parents' => [$folderId],
        ]);

        $file = $this->drive->files->create($fileMeta, [
            'data'       => $fileContent,
            'mimeType'   => $mimeType,
            'uploadType' => 'multipart',
            'fields'     => 'id',
        ]);

        return $file->getId();
    }

    /**
     * Hapus file atau folder dari Google Drive berdasarkan ID.
     */
    public function deleteItem(string $fileId): void
    {
        $this->drive->files->delete($fileId);
    }

    /**
     * Cari atau buat folder "archive" di dalam root folder.
     */
    public function findOrCreateArchiveFolder(string $rootFolderId): string
    {
        return $this->findOrCreateFolder($rootFolderId, 'archive');
    }

    /**
     * Cari atau buat subfolder "Invoice B2B" atau "Invoice B2C" di dalam root folder.
     */
    public function findOrCreateInvoiceTypeFolder(string $rootFolderId, string $type): string
    {
        return $this->findOrCreateFolder($rootFolderId, 'Invoice ' . $type);
    }

    /**
     * Cari atau buat subfolder per-invoice di dalam archive folder.
     */
    public function findOrCreateInvoiceFolder(string $archiveFolderId, string $invoiceNo): string
    {
        $folderName = 'Invoice-' . $this->sanitizeFolderName($invoiceNo);
        return $this->findOrCreateFolder($archiveFolderId, $folderName);
    }

    /**
     * Set permission anyone/reader pada file sehingga bisa diakses via share link.
     */
    public function makeFilePublic(string $fileId): void
    {
        $permission = new \Google\Service\Drive\Permission([
            'type' => 'anyone',
            'role' => 'reader',
        ]);

        $this->drive->permissions->create($fileId, $permission);
    }

    /**
     * Cari atau buat subfolder generik (tahun, bulan, dll) di dalam parent folder.
     */
    public function findOrCreateSubFolder(string $parentFolderId, string $folderName): string
    {
        return $this->findOrCreateFolder($parentFolderId, $folderName);
    }

    /**
     * Hapus karakter yang tidak valid untuk nama folder Google Drive.
     */
    private function sanitizeFolderName(string $name): string
    {
        $safe = preg_replace('/[\/\\\\:*?"<>|]/', '-', $name);
        return mb_substr(trim($safe), 0, 255);
    }

    /**
     * Cari folder berdasarkan nama di dalam parent folder, buat jika belum ada.
     */
    private function findOrCreateFolder(string $parentFolderId, string $folderName): string
    {
        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and name='%s' and '%s' in parents and trashed=false",
            addslashes($folderName),
            $parentFolderId
        );

        $results = $this->drive->files->listFiles([
            'q'      => $query,
            'fields' => 'files(id, name)',
        ]);

        if (count($results->getFiles()) > 0) {
            return $results->getFiles()[0]->getId();
        }

        $folderMeta = new DriveFile([
            'name'     => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parentFolderId],
        ]);

        $folder = $this->drive->files->create($folderMeta, ['fields' => 'id']);

        Log::info('GoogleDriveService: folder dibuat', [
            'nama'      => $folderName,
            'folder_id' => $folder->getId(),
        ]);

        return $folder->getId();
    }
}
