<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApShz360PoImport extends Model
{
    protected $table = 'tb_ap_shz360_po_imports';

    protected $fillable = [
        'source_po_id',
        'kode_po',
        'tanggal_po',
        'source_supplier_id',
        'vendor_ap_id',
        'status_po',
        'subtotal',
        'ongkir',
        'diskon',
        'grand_total',
        'source_updated_at',
        'source_hash',
        'import_status',
        'raw_payload',
    ];

    protected $casts = [
        'tanggal_po' => 'date',
        'subtotal' => 'decimal:2',
        'ongkir' => 'decimal:2',
        'diskon' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'source_updated_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function vendorAp()
    {
        return $this->belongsTo(VendorAp::class, 'vendor_ap_id');
    }

    public function items()
    {
        return $this->hasMany(ApShz360PoImportItem::class, 'po_import_id');
    }

    public function receipts()
    {
        return $this->hasMany(ApShz360ReceiptImport::class, 'po_import_id');
    }
}
