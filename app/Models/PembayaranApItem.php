<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembayaranApItem extends Model
{
    protected $table = 'tb_pembayaran_ap_items';

    protected $fillable = [
        'pembayaran_ap_id',
        'tagihan_ap_id',
        'vendor_ap_id',
        'jumlah_dialokasikan',
        'sisa_sebelum',
        'sisa_sesudah',
    ];

    protected $casts = [
        'jumlah_dialokasikan' => 'decimal:2',
        'sisa_sebelum'        => 'decimal:2',
        'sisa_sesudah'        => 'decimal:2',
    ];

    public function pembayaranAp()
    {
        return $this->belongsTo(PembayaranAp::class, 'pembayaran_ap_id');
    }

    public function tagihanAp()
    {
        return $this->belongsTo(TagihanAp::class, 'tagihan_ap_id');
    }

    public function vendorAp()
    {
        return $this->belongsTo(VendorAp::class, 'vendor_ap_id');
    }
}
