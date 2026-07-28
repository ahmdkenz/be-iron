<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris item invoice hasil parsing file Excel. */
class InvoiceImportRow extends Model
{
    protected $table = 'tb_invoice_import_rows';

    protected $fillable = [
        'batch_id',
        'group_id',
        'row_number',
        'no_invoice_resto',
        'kode_resto',
        'nama_resto',
        'kode_barang',
        'nama_barang',
        'barang_id',
        'qty',
        'satuan',
        'harga_satuan',
        'subtotal',
        'error',
    ];

    protected $casts = [
        'row_number'   => 'integer',
        'qty'          => 'decimal:2',
        'harga_satuan' => 'decimal:2',
        'subtotal'     => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvoiceImportBatch::class, 'batch_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(InvoiceImportGroup::class, 'group_id');
    }
}
