<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApShz360SyncError extends Model
{
    protected $table = 'tb_ap_shz360_sync_errors';

    protected $fillable = [
        'sync_run_id',
        'source_type',
        'source_id',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function syncRun()
    {
        return $this->belongsTo(ApShz360SyncRun::class, 'sync_run_id');
    }
}
