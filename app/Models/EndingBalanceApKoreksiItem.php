<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndingBalanceApKoreksiItem extends Model
{
    protected $table = 'tb_ending_balance_ap_koreksi_item';

    protected $fillable = [
        'ending_balance_ap_koreksi_id',
        'tagihan_ap_item_id',
        'nama_barang',
        'qty_lama',
        'harga_satuan_lama',
        'subtotal_lama',
        'qty_baru',
        'harga_satuan_baru',
        'subtotal_baru',
        'selisih',
    ];

    protected $casts = [
        'qty_lama'          => 'decimal:3',
        'harga_satuan_lama' => 'decimal:2',
        'subtotal_lama'     => 'decimal:2',
        'qty_baru'          => 'decimal:3',
        'harga_satuan_baru' => 'decimal:2',
        'subtotal_baru'     => 'decimal:2',
        'selisih'           => 'decimal:2',
    ];

    public function koreksi()
    {
        return $this->belongsTo(EndingBalanceApKoreksi::class, 'ending_balance_ap_koreksi_id');
    }

    public function tagihanApItem()
    {
        return $this->belongsTo(TagihanApItem::class, 'tagihan_ap_item_id');
    }
}
