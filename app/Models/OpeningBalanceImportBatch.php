<?php

namespace App\Models;

use App\Models\Concerns\HasCancelableImport;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class OpeningBalanceImportBatch extends Model
{
    use HasUuids, HasCancelableImport;

    protected $table = 'tb_opening_balance_import_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'original_filename',
        'file_path',
        'is_csv',
        'cutover_date',
        'status',
        'total_ob',
        'processed_ob',
        'inserted_ob',
        'skipped_ob',
        'failed_ob',
        'total_detail',
        'inserted_detail',
        'total_item',
        'inserted_item',
        'errors',
        'message',
    ];

    protected $casts = [
        'is_csv' => 'boolean',
        'cutover_date' => 'date',
        'total_ob' => 'integer',
        'processed_ob' => 'integer',
        'inserted_ob' => 'integer',
        'skipped_ob' => 'integer',
        'failed_ob' => 'integer',
        'total_detail' => 'integer',
        'inserted_detail' => 'integer',
        'total_item' => 'integer',
        'inserted_item' => 'integer',
        'errors' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * "Menunggu konfirmasi" (lihat OpeningBalanceImportService::process()) TIDAK punya
     * nilai enum status sendiri — direpresentasikan sebagai sub-state dari 'processing'
     * ($batch->errors['awaiting_confirmation'] === true) supaya tidak perlu migration
     * baru. Batch begitu SENGAJA berhenti menunggu keputusan manusia, bukan worker yang
     * mati — harus dikecualikan dari auto-fail di sini (beda urusan dari
     * cancelAbandonedConfirmations() di bawah, yang punya window waktu terpisah & lebih
     * panjang).
     */
    public static function failStale(int $minutes = 35): int
    {
        $stale = static::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        $count = 0;
        foreach ($stale as $batch) {
            if (($batch->errors['awaiting_confirmation'] ?? false) === true) {
                continue;
            }

            $batch->update([
                'status' => 'failed',
                'message' => 'Import terhenti tak terduga (worker kemungkinan dihentikan server). Data yang sudah masuk tetap tersimpan; ulangi import untuk sisa data.',
            ]);
            $count++;
        }

        return $count;
    }

    /** Batch yang masih dianggap "aktif" — dipakai guard mutex sebelum menerima upload baru. */
    public static function active(): ?self
    {
        return static::whereIn('status', ['queued', 'processing'])->latest('created_at')->first();
    }

    public function cancel(string $message): void
    {
        if ($this->file_path) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->update(['status' => 'failed', 'message' => $message]);

        $this->clearCancelRequest();
        $this->clearCachedPhase();
    }

    /**
     * Batch yang berhenti menunggu konfirmasi (lihat failStale() di atas) tapi
     * ditinggalkan user tanpa keputusan — dibatalkan otomatis supaya tidak mengunci
     * mutex active() selamanya. Window (60 menit) sengaja lebih panjang dari
     * failStale() (35 menit) karena ini murni menunggu manusia, bukan mendeteksi
     * worker yang crash.
     */
    public static function cancelAbandonedConfirmations(int $minutes = 60): int
    {
        $stale = static::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get()
            ->filter(fn (self $batch) => ($batch->errors['awaiting_confirmation'] ?? false) === true);

        foreach ($stale as $batch) {
            $batch->cancel('Import dibatalkan otomatis karena tidak ada konfirmasi dalam '.$minutes.' menit.');
        }

        return $stale->count();
    }
}
