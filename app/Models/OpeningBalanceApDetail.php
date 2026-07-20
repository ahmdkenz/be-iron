<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalanceApDetail extends Model
{
    protected $table = 'tb_opening_balance_ap_detail';

    protected $fillable = [
        'tagihan_ap_id',
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

    public function tagihanAp()
    {
        return $this->belongsTo(TagihanAp::class, 'tagihan_ap_id');
    }

    public function items()
    {
        return $this->hasMany(OpeningBalanceApDetailItem::class, 'ob_detail_id');
    }
}
