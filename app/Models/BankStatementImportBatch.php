<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImportBatch extends Model
{
    use HasUuids;

    protected $table = 'tb_bank_statement_import_batch';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'bank_type',
        'original_filename',
        'file_path',
        'status',
        'phase',
        'message',
        'periode_awal',
        'periode_akhir',
        'total_rows',
        'processed_rows',
        'inserted_rows',
        'matched_rows',
        'unmatched_rows',
        'ignored_rows',
        'error_rows',
        'total_kredit',
        'bank_statement_id',
        'force_replace',
        'overlaps',
        'errors',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'periode_awal'   => 'date',
        'periode_akhir'  => 'date',
        'total_rows'     => 'integer',
        'processed_rows' => 'integer',
        'inserted_rows'  => 'integer',
        'matched_rows'   => 'integer',
        'unmatched_rows' => 'integer',
        'ignored_rows'   => 'integer',
        'error_rows'     => 'integer',
        'total_kredit'   => 'float',
        'force_replace'  => 'boolean',
        'overlaps'       => 'array',
        'errors'         => 'array',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BankStatementImportRow::class, 'batch_id');
    }

    /**
     * Batch yang "processing" tapi worker-nya mati/berhenti tak terduga akan
     * menggantung selamanya di FE (polling tidak pernah menemui status terminal).
     * Ambang 35 menit sengaja di atas timeout job (1800 detik = 30 menit).
     */
    public static function failStale(int $minutes = 35): int
    {
        return static::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->update([
                'status'      => 'failed',
                'phase'       => 'failed',
                'message'     => 'Import terhenti tak terduga (worker kemungkinan dihentikan server). Silakan ulangi upload.',
                'finished_at' => now(),
            ]);
    }

    /**
     * Batch yang berhenti di needs_confirmation (menunggu user memilih "Ganti
     * dengan File Baru" di dialog overlap) tidak otomatis dibersihkan oleh
     * ImportBankStatementJob::cleanup() — file upload & staging rows
     * (tb_bank_statement_import_rows) sengaja dipertahankan untuk redispatch via
     * confirm-replace. Kalau user menutup tab tanpa konfirmasi/batal, keduanya
     * akan menggantung permanen. Safety net ini membersihkannya setelah dianggap
     * ditinggalkan (default 60 menit sejak update terakhir).
     */
    public static function cancelAbandonedConfirmations(int $minutes = 60): int
    {
        $stale = static::where('status', 'needs_confirmation')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($stale as $batch) {
            $batch->cancel('Import dibatalkan otomatis karena tidak ada konfirmasi dalam ' . $minutes . ' menit.');
        }

        return $stale->count();
    }

    /**
     * Hapus file upload + staging rows lalu tandai batch gagal dengan phase
     * khusus 'canceled' (status tetap memakai enum 'failed' yang sudah ada —
     * tidak perlu ALTER enum baru — dibedakan lewat phase & message).
     */
    public function cancel(string $message): void
    {
        if ($this->file_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($this->file_path);
        }

        $this->rows()->delete();

        $this->update([
            'status'      => 'failed',
            'phase'       => 'canceled',
            'message'     => $message,
            'finished_at' => now(),
        ]);
    }
}
