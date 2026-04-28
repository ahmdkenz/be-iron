<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class Resto extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_resto';

    protected $fillable = [
        'kode_resto',
        'nama_resto',
        'investor_id',
        'perusahaan_id',
        'brand_id',
        'karyawan_id',
        'area',
        'kota',
        'alamat',
        'no_telp',
        'tgl_aktif',
        'keterangan',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tgl_aktif' => 'date',
        'status'    => 'boolean',
    ];

    public function perusahaan()
    {
        return $this->belongsTo(Perusahaan::class, 'perusahaan_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function investor()
    {
        return $this->belongsTo(Investor::class, 'investor_id');
    }

    public function pic()
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id');
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
