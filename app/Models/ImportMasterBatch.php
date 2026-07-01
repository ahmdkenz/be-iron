<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ImportMasterBatch extends Model
{
    use HasUuids;

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
        'barang_total',
        'barang_processed',
        'barang_inserted',
        'barang_updated',
        'barang_failed',
        'invoice_total',
        'invoice_processed',
        'invoice_inserted',
        'invoice_updated',
        'invoice_skipped',
        'invoice_failed',
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
        'barang_total'      => 'integer',
        'barang_processed'  => 'integer',
        'barang_inserted'   => 'integer',
        'barang_updated'    => 'integer',
        'barang_failed'     => 'integer',
        'invoice_total'     => 'integer',
        'invoice_processed' => 'integer',
        'invoice_inserted'  => 'integer',
        'invoice_updated'   => 'integer',
        'invoice_skipped'   => 'integer',
        'invoice_failed'    => 'integer',
        'errors'            => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

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
