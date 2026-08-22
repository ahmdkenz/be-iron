<?php

namespace App\Models;

use App\Models\Concerns\HasCancelableImport;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ImportMasterBatch extends Model
{
    use HasUuids, HasCancelableImport;

    protected $table = 'tb_import_master_batch';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'file_path',
        'status',
        'master_total',
        'master_processed',
        'investor_inserted',
        'investor_updated',
        'investor_failed',
        'resto_inserted',
        'resto_updated',
        'resto_failed',
        'klien_inserted',
        'klien_updated',
        'klien_failed',
        'klien_skipped',
        'barang_total',
        'barang_processed',
        'barang_inserted',
        'barang_updated',
        'barang_skipped',
        'barang_failed',
        'errors',
        'message',
    ];

    protected $casts = [
        'master_total'      => 'integer',
        'master_processed'  => 'integer',
        'investor_inserted' => 'integer',
        'investor_updated'  => 'integer',
        'investor_failed'   => 'integer',
        'resto_inserted'    => 'integer',
        'resto_updated'     => 'integer',
        'resto_failed'      => 'integer',
        'klien_inserted'    => 'integer',
        'klien_updated'     => 'integer',
        'klien_failed'      => 'integer',
        'klien_skipped'     => 'integer',
        'barang_total'      => 'integer',
        'barang_processed'  => 'integer',
        'barang_inserted'   => 'integer',
        'barang_updated'    => 'integer',
        'barang_skipped'    => 'integer',
        'barang_failed'     => 'integer',
        'errors'            => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Seluruh penulisan entitas (Investor/Resto/Client/Barang) dibungkus SATU transaksi
     * besar (lihat MasterImportService::process()) — worker yang mati/di-kill di tengah
     * jalan berarti koneksi DB-nya putus, transaksi otomatis ROLLBACK oleh MySQL, jadi
     * TIDAK ada data yang tersisa dari batch itu (beda dari perilaku lama sebelum
     * restrukturisasi ini, yang commit tiap 100 baris).
     */
    public static function failStale(int $processingMinutes = 15, int $queuedMinutes = 5): int
    {
        $failedProcessing = static::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($processingMinutes))
            ->update([
                'status'  => 'failed',
                'message' => 'Import terhenti tak terduga (worker kemungkinan dihentikan server). Tidak ada data yang tersimpan — silakan ulangi upload dari awal.',
            ]);

        // Batch 'queued' yang belum diambil job setelah beberapa menit menandakan
        // tidak ada worker yang mendengarkan queue-nya (bukan proses yang terhenti
        // di tengah jalan seperti kasus 'processing' di atas) — ambang jauh lebih
        // pendek karena job semestinya di-dequeue nyaris seketika saat worker aktif.
        $failedQueued = static::where('status', 'queued')
            ->where('created_at', '<', now()->subMinutes($queuedMinutes))
            ->update([
                'status'  => 'failed',
                'message' => 'Import tidak pernah diproses (worker tidak aktif/tidak tersedia). Silakan hubungi admin, lalu ulangi upload setelah worker berjalan.',
            ]);

        return $failedProcessing + $failedQueued;
    }

    public function cancel(string $message): void
    {
        if ($this->file_path) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->update([
            'status'  => 'failed',
            'message' => $message,
        ]);

        $this->clearCancelRequest();
        $this->clearCachedPhase();
    }
}
