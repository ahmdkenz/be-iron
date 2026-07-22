<?php

namespace App\Domain\Finance\ApShz360Sync\Exceptions;

use RuntimeException;

/**
 * Dilempar saat runFullSync() dipanggil sementara lock sync lain masih aktif
 * (scheduler & tombol manual retry berebut proses yang sama).
 */
class ApShz360SyncInProgressException extends RuntimeException
{
    public function __construct(string $message = 'Sync sedang berjalan, tunggu selesai.')
    {
        parent::__construct($message);
    }
}
