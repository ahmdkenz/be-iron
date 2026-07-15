<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApShz360SyncRun extends Model
{
    protected $table = 'tb_ap_shz360_sync_runs';

    protected $fillable = [
        'started_at',
        'finished_at',
        'status',
        'po_fetched',
        'po_upserted',
        'receipt_fetched',
        'receipt_upserted',
        'message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'po_fetched' => 'integer',
        'po_upserted' => 'integer',
        'receipt_fetched' => 'integer',
        'receipt_upserted' => 'integer',
    ];

    public function errors()
    {
        return $this->hasMany(ApShz360SyncError::class, 'sync_run_id');
    }
}
