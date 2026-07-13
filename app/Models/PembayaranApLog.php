<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PembayaranApLog extends Model
{
    protected $table = 'tb_pembayaran_ap_log';

    protected $fillable = [
        'pembayaran_ap_id',
        'aksi',
        'actor_id',
        'data_sebelum',
        'data_sesudah',
        'keterangan',
    ];

    protected $casts = [
        'data_sebelum' => 'array',
        'data_sesudah' => 'array',
    ];

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranAp::class, 'pembayaran_ap_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
