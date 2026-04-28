<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class Perusahaan extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_perusahaan';

    protected $fillable = [
        'kode_perusahaan',
        'nama_perusahaan',
        'nama_singkatan_perusahaan',
        'alamat',
        'kota',
        'kode_pos',
        'no_telp',
        'email',
        'no_npwp',
        'status',
        'keterangan',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function brands()
    {
        return $this->hasMany(Brand::class, 'perusahaan_id');
    }

    public function karyawans()
    {
        return $this->hasMany(Karyawan::class, 'perusahaan_id');
    }

    public function restos()
    {
        return $this->hasMany(Resto::class, 'perusahaan_id');
    }

    public function barangs()
    {
        return $this->hasMany(Barang::class, 'perusahaan_id');
    }
}
