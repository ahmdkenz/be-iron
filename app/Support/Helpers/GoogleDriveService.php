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
        $credentialsPath = base_path(config('services.google_drive.credentials_path'));

        $client = new GoogleClient();
        $client->setAuthConfig($credentialsPath);
        $client->addScope(GoogleDrive::DRIVE);

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
        $fileMeta = new DriveFile([
            'name'    => $fileName,
            'parents' => [$folderId],
        ]);

        $file = $this->drive->files->create($fileMeta, [
            'data'       => $pdfContent,
            'mimeType'   => 'application/pdf',
            'uploadType' => 'multipart',
            'fields'     => 'id',
        ]);

        return $file->getId();
    }

    /**
     * Hapus karakter yang tidak valid untuk nama folder Google Drive.
     */
    private function sanitizeFolderName(string $name): string
    {
        $safe = preg_replace('/[\/\\\\:*?"<>|]/', '-', $name);
        return mb_substr(trim($safe), 0, 255);
    }
}
