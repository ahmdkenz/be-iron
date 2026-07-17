<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use Illuminate\Database\Eloquent\Model;

class VendorAp extends Model
{
    use BlameableTrait;

    protected $table = 'tb_vendor_ap';

    protected $fillable = [
        'kode_vendor',
        'nama_vendor',
        'no_npwp',
        'status_pkp',
        'bank_nama',
        'bank_no_rekening',
        'bank_atas_nama',
        'karyawan_ap_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status_pkp'   => 'boolean',
        'status'       => 'boolean',
    ];

    public function karyawanAp()
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_ap_id');
    }

    public function tagihanAp()
    {
        return $this->hasMany(TagihanAp::class, 'vendor_ap_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
