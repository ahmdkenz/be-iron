<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class Karyawan extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_karyawan';

    protected $fillable = [
        'nik',
        'nama_karyawan',
        'perusahaan_id',
        'status',
        'keterangan',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function perusahaan()
    {
        return $this->belongsTo(Perusahaan::class, 'perusahaan_id');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'karyawan_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function restoSebagaiPic()
    {
        return $this->hasMany(Resto::class, 'karyawan_id');
    }
}
