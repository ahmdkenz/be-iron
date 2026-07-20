<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalanceApDetailItem extends Model
{
    protected $table = 'tb_opening_balance_ap_detail_item';

    protected $fillable = [
        'ob_detail_id',
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

    public function obDetail()
    {
        return $this->belongsTo(OpeningBalanceApDetail::class, 'ob_detail_id');
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }
}
