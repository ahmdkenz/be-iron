<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApSourceVendorMap extends Model
{
    protected $table = 'tb_ap_source_vendor_maps';

    protected $fillable = [
        'source_system',
        'source_supplier_id',
        'source_supplier_code',
        'source_supplier_name',
        'vendor_ap_id',
        'perusahaan_id',
        'status',
        'raw_payload',
        'last_synced_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function vendorAp()
    {
        return $this->belongsTo(VendorAp::class, 'vendor_ap_id');
    }

    public function perusahaan()
    {
        return $this->belongsTo(Perusahaan::class, 'perusahaan_id');
    }
}
