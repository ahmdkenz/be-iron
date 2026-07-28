<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu calon invoice hasil grouping baris Excel
 * (tipe_invoice + klien_ar_id + tanggal_invoice).
 */
class InvoiceImportGroup extends Model
{
    protected $table = 'tb_invoice_import_groups';

    /** Boleh diproses otomatis lewat "Proses Data Aman". */
    public const SAFE_CLASSIFICATIONS = ['NEW_INVOICE', 'SAFE_UPDATE'];

    protected $fillable = [
        'batch_id',
        'group_key',
        'tipe_invoice',
        'nama_klien',
        'kode_resto',
        'tanggal_invoice',
        'tanggal_jatuh_tempo',
        'no_surat_jalan',
        'keterangan',
        'first_line',
        'item_count',
        'klien_ar_id',
        'invoice_id',
        'no_invoice',
        'classification',
        'reason',
        'risk_flags',
        'header_diff',
        'total_lama',
        'total_baru',
        'selisih',
        'adjustment_type',
        'review_status',
        'koreksi_id',
        'decided_by',
        'decided_at',
        'review_message',
        'apply_status',
        'apply_message',
    ];

    protected $casts = [
        'tanggal_invoice'     => 'date',
        'tanggal_jatuh_tempo' => 'date',
        'first_line'          => 'integer',
        'item_count'          => 'integer',
        'risk_flags'          => 'array',
        'header_diff'         => 'array',
        'total_lama'          => 'decimal:2',
        'total_baru'          => 'decimal:2',
        'selisih'             => 'decimal:2',
        'decided_at'          => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvoiceImportBatch::class, 'batch_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(InvoiceImportRow::class, 'group_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function klienAr(): BelongsTo
    {
        return $this->belongsTo(KlienAr::class, 'klien_ar_id');
    }

    public function koreksi(): BelongsTo
    {
        return $this->belongsTo(EndingBalanceKoreksi::class, 'koreksi_id');
    }

    public function isSafe(): bool
    {
        return in_array($this->classification, self::SAFE_CLASSIFICATIONS, true);
    }

    public function needsReview(): bool
    {
        return $this->classification === 'REVIEW_REQUIRED';
    }
}
