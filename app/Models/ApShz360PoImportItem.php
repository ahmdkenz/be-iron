<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApShz360PoImportItem extends Model
{
    protected $table = 'tb_ap_shz360_po_import_items';

    protected $fillable = [
        'po_import_id',
        'source_po_item_id',
        'source_barang_id',
        'kode_barang',
        'nama_barang',
        'satuan',
        'qty_po',
        'harga',
        'ppn',
        'subtotal',
        'raw_payload',
    ];

    protected $casts = [
        'qty_po' => 'decimal:4',
        'harga' => 'decimal:2',
        'ppn' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    public function poImport()
    {
        return $this->belongsTo(ApShz360PoImport::class, 'po_import_id');
    }
}
