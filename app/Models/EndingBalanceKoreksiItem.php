<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndingBalanceKoreksiItem extends Model
{
    protected $table = 'tb_ending_balance_koreksi_item';

    protected $fillable = [
        'ending_balance_koreksi_id',
        'invoice_item_id',
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
        return $this->belongsTo(EndingBalanceKoreksi::class, 'ending_balance_koreksi_id');
    }

    public function invoiceItem()
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }
}
