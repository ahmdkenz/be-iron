<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RestoImportBatch extends Model
{
    use HasUuids;

    protected $table = 'tb_resto_import_batch';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'file_path',
        'status',
        'total',
        'processed',
        'total_data',
        'inserted',
        'updated',
        'failed',
        'errors',
        'message',
    ];

    protected $casts = [
        'total'      => 'integer',
        'processed'  => 'integer',
        'total_data' => 'integer',
        'inserted'   => 'integer',
        'updated'    => 'integer',
        'failed'     => 'integer',
        'errors'     => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Tandai batch yang nyangkut di 'processing' (tanpa progress lebih lama
     * dari $minutes) sebagai 'failed'. Dipakai sebagai safeguard saat worker
     * dihentikan host di tengah proses sehingga status tidak tertinggal di
     * 'processing' selamanya. Data yang sudah ter-commit tetap tersimpan
     * karena import memakai commit bertahap.
     *
     * @return int jumlah batch yang ditandai gagal
     */
    public static function failStale(int $minutes = 15): int
    {
        return static::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->update([
                'status'  => 'failed',
                'message' => 'Import terhenti tak terduga (worker kemungkinan dihentikan server). Data yang sudah masuk tetap tersimpan; ulangi import untuk sisa data.',
            ]);
    }
}
