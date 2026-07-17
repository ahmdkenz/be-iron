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
        'qty_po',
        'satuan',
        'harga_satuan',
        'ppn',
        'status_detail_terima_po',
        'qty_tolak',
        'keterangan_tolak',
        'subtotal',
        'keterangan',
    ];

    protected $casts = [
        'qty'          => 'decimal:3',
        'qty_po'       => 'decimal:4',
        'harga_satuan' => 'decimal:2',
        'ppn'          => 'decimal:2',
        'qty_tolak'    => 'decimal:4',
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
