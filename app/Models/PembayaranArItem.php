<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembayaranArItem extends Model
{
    protected $table = 'tb_pembayaran_ar_items';

    protected $fillable = [
        'pembayaran_ar_id',
        'invoice_id',
        'klien_ar_id',
        'jumlah_dialokasikan',
        'sisa_sebelum',
        'sisa_sesudah',
    ];

    protected $casts = [
        'jumlah_dialokasikan' => 'decimal:2',
        'sisa_sebelum'        => 'decimal:2',
        'sisa_sesudah'        => 'decimal:2',
    ];

    public function pembayaranAr()
    {
        return $this->belongsTo(PembayaranAr::class, 'pembayaran_ar_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function klienAr()
    {
        return $this->belongsTo(KlienAr::class, 'klien_ar_id');
    }
}
