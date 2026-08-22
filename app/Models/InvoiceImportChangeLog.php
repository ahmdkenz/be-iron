<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris hasil "Proses Data Aman" pada Import Master Invoice — ditulis oleh
 * InvoiceImportService lewat DB::table()->insert() (bulk), model ini dipakai sisi
 * baca (InvoiceImportController::changeLog()) saja. Mirror
 * OpeningBalanceImportChangeLog (single-entity, tanpa entity_type).
 */
class InvoiceImportChangeLog extends Model
{
    protected $table = 'tb_invoice_import_change_log';

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
        return $this->belongsTo(InvoiceImportBatch::class, 'batch_id');
    }
}
