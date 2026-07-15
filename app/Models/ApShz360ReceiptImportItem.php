<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApShz360ReceiptImportItem extends Model
{
    protected $table = 'tb_ap_shz360_receipt_import_items';

    protected $fillable = [
        'receipt_import_id',
        'po_item_import_id',
        'source_receipt_item_id',
        'source_barang_id',
        'kode_barang',
        'nama_barang',
        'satuan',
        'status_detail_terima_po',
        'qty_diterima',
        'qty_tolak',
        'keterangan_tolak',
        'harga',
        'subtotal',
        'raw_payload',
    ];

    protected $casts = [
        'qty_diterima' => 'decimal:4',
        'qty_tolak' => 'decimal:4',
        'harga' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    public function receiptImport()
    {
        return $this->belongsTo(ApShz360ReceiptImport::class, 'receipt_import_id');
    }

    public function poImportItem()
    {
        return $this->belongsTo(ApShz360PoImportItem::class, 'po_item_import_id');
    }
}
