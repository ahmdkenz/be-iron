<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris hasil proses Import Master Opening Balance — ditulis oleh
 * OpeningBalanceImportService lewat DB::table()->insert() (bulk), model ini dipakai
 * sisi baca (OpeningBalanceController::importChangeLog()) saja. Mirror
 * ImportMasterChangeLog, minus entity_type (OB AR single-entity).
 */
class OpeningBalanceImportChangeLog extends Model
{
    protected $table = 'tb_opening_balance_import_change_log';

    protected $fillable = [
        'batch_id',
        'row_number',
        'change_type',
        'data_sebelum',
        'data_baru',
        'message',
        'search_text',
    ];

    protected $casts = [
        'row_number'   => 'integer',
        'data_sebelum' => 'array',
        'data_baru'    => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceImportBatch::class, 'batch_id');
    }
}
