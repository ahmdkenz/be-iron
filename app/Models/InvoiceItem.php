<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'tb_invoice_item';

    protected $fillable = [
        'invoice_id',
        'barang_id',
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

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }
}
