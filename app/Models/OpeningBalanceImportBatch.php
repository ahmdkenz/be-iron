<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OpeningBalanceImportBatch extends Model
{
    use HasUuids;

    protected $table = 'tb_opening_balance_import_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'original_filename',
        'file_path',
        'is_csv',
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

    public static function failStale(int $minutes = 35): int
    {
        return static::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => 'failed',
                'message' => 'Import terhenti tak terduga (worker kemungkinan dihentikan server). Data yang sudah masuk tetap tersimpan; ulangi import untuk sisa data.',
            ]);
    }

    /** Batch yang masih dianggap "aktif" — dipakai guard mutex sebelum menerima upload baru. */
    public static function active(): ?self
    {
        return static::whereIn('status', ['queued', 'processing'])->latest('created_at')->first();
    }
}
