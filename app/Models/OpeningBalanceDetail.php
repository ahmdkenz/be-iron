<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalanceDetail extends Model
{
    protected $table = 'tb_opening_balance_detail';

    protected $fillable = [
        'invoice_id',
        'no_invoice_asal',
        'tanggal_invoice_asal',
        'deskripsi',
        'jumlah_tagihan_asal',
        'sisa_tagihan_asal',
        'keterangan',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_invoice_asal' => 'date',
        'jumlah_tagihan_asal'  => 'decimal:2',
        'sisa_tagihan_asal'    => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function items()
    {
        return $this->hasMany(OpeningBalanceDetailItem::class, 'ob_detail_id');
    }
}
