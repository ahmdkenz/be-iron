<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagihanApItem extends Model
{
    protected $table = 'tb_tagihan_ap_item';

    protected $fillable = [
        'tagihan_ap_id',
        'barang_id',
        'kode_barang',
        'nama_barang',
        'qty',
        'satuan',
        'harga_satuan',
        'subtotal',
        'keterangan',
    ];

    protected $casts = [
        'qty'          => 'decimal:3',
        'harga_satuan' => 'decimal:2',
        'subtotal'     => 'decimal:2',
    ];

    public function tagihanAp()
    {
        return $this->belongsTo(TagihanAp::class, 'tagihan_ap_id');
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }
}
