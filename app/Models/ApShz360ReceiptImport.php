<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApShz360ReceiptImport extends Model
{
    protected $table = 'tb_ap_shz360_receipt_imports';

    protected $fillable = [
        'po_import_id',
        'source_receipt_id',
        'kode_receipt',
        'tanggal_receipt',
        'no_invoice',
        'no_surat_jalan',
        'no_faktur_pajak',
        'import_status',
        'source_updated_at',
        'source_hash',
        'raw_payload',
    ];

    protected $casts = [
        'tanggal_receipt' => 'date',
        'source_updated_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function poImport()
    {
        return $this->belongsTo(ApShz360PoImport::class, 'po_import_id');
    }

    public function items()
    {
        return $this->hasMany(ApShz360ReceiptImportItem::class, 'receipt_import_id');
    }

    public function tagihanLink()
    {
        return $this->hasOne(ApImportTagihanLink::class, 'receipt_import_id');
    }
}
