<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris hasil proses import Master Data (Investor/Resto/Client AR/Barang) —
 * ditulis oleh MasterImportService lewat DB::table()->insert() (bulk), model ini
 * dipakai sisi baca (UnifiedMasterController::importChangeLog()) saja.
 */
class ImportMasterChangeLog extends Model
{
    protected $table = 'tb_import_master_change_log';

    protected $fillable = [
        'batch_id',
        'entity_type',
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
        return $this->belongsTo(ImportMasterBatch::class, 'batch_id');
    }
}
