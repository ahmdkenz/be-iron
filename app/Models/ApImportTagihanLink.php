<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApImportTagihanLink extends Model
{
    protected $table = 'tb_ap_import_tagihan_links';

    protected $fillable = [
        'tagihan_ap_id',
        'po_import_id',
        'receipt_import_id',
        'amount_allocated',
        'created_by',
    ];

    protected $casts = [
        'amount_allocated' => 'decimal:2',
    ];

    public function tagihanAp()
    {
        return $this->belongsTo(TagihanAp::class, 'tagihan_ap_id');
    }

    public function poImport()
    {
        return $this->belongsTo(ApShz360PoImport::class, 'po_import_id');
    }

    public function receiptImport()
    {
        return $this->belongsTo(ApShz360ReceiptImport::class, 'receipt_import_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
