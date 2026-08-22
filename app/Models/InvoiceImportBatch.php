<?php

namespace App\Models;

use App\Models\Concerns\HasCancelableImport;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class InvoiceImportBatch extends Model
{
    use HasUuids, HasCancelableImport;

    protected $table = 'tb_invoice_import_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'original_filename',
        'file_path',
        'status',
        'phase',
        'message',
        'total_rows',
        'parsed_rows',
        'total_groups',
        'classified_groups',
        'cnt_new',
        'cnt_unchanged',
        'cnt_safe_update',
        'cnt_review_required',
        'cnt_rejected',
        'applied_total',
        'applied_processed',
        'applied_inserted',
        'applied_updated',
        'applied_skipped',
        'applied_failed',
        'errors',
        'started_at',
        'classified_at',
        'applied_at',
        'finished_at',
    ];

    protected $casts = [
        'total_rows'             => 'integer',
        'parsed_rows'            => 'integer',
        'total_groups'           => 'integer',
        'classified_groups'      => 'integer',
        'cnt_new'                => 'integer',
        'cnt_unchanged'          => 'integer',
        'cnt_safe_update'        => 'integer',
        'cnt_review_required'    => 'integer',
        'cnt_rejected'           => 'integer',
        'applied_total'          => 'integer',
        'applied_processed'      => 'integer',
        'applied_inserted'       => 'integer',
        'applied_updated'        => 'integer',
        'applied_skipped'        => 'integer',
        'applied_failed'         => 'integer',
        'errors'                 => 'array',
        'started_at'             => 'datetime',
        'classified_at'          => 'datetime',
        'applied_at'             => 'datetime',
        'finished_at'            => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(InvoiceImportGroup::class, 'batch_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(InvoiceImportRow::class, 'batch_id');
    }

    /** Batch masih menunggu keputusan user (belum "Proses Data Aman"). */
    public function isAwaitingReview(): bool
    {
        return $this->status === 'awaiting_review';
    }

    /** Status yang tidak lagi berubah tanpa aksi user (polling FE boleh berhenti). */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['awaiting_review', 'completed', 'failed'], true);
    }

    /**
     * Batch yang "processing"/"parsing" tapi worker-nya mati akan menggantung
     * selamanya di FE (polling tidak pernah menemui status terminal). Ambang 35
     * menit sengaja di atas timeout job (1800 detik = 30 menit).
     */
    public static function failStale(int $minutes = 35): int
    {
        return static::whereIn('status', ['queued', 'parsing', 'classifying', 'processing'])
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->update([
                'status'      => 'failed',
                'phase'       => 'failed',
                'message'     => 'Import terhenti tak terduga (worker kemungkinan dihentikan server). Silakan ulangi upload.',
                'finished_at' => now(),
            ]);
    }

    /**
     * Batalkan batch yang menggantung di awaiting_review — hapus file upload +
     * seluruh staging (groups & rows) lalu tandai gagal dengan phase khusus
     * 'canceled' (status tetap memakai enum 'failed' yang sudah ada, dibedakan
     * lewat phase & message). Batch (header) TIDAK dihapus supaya tetap ada
     * jejak audit siapa upload apa dan kenapa dibatalkan.
     *
     * Riwayat perubahan (tb_invoice_import_change_log) milik batch ini TIDAK ikut
     * dihapus di sini — pembatalan hanya mungkin saat awaiting_review, yaitu sebelum
     * satu baris pun ditulis, jadi memang belum ada riwayat yang terbentuk.
     *
     * rows() dihapus SEBELUM groups() dengan sengaja: FK group_id pada
     * tb_invoice_import_rows cuma nullOnDelete (bukan cascade), jadi kalau
     * groups dihapus duluan baris rows akan jadi orphan permanen.
     */
    public function cancel(string $message): void
    {
        if ($this->file_path) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->rows()->delete();
        $this->groups()->delete();

        $this->update([
            'status'      => 'failed',
            'phase'       => 'canceled',
            'message'     => $message,
            'finished_at' => now(),
        ]);

        $this->clearCancelRequest();
    }
}
