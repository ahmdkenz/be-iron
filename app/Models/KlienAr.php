<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class KlienAr extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_klien_ar';

    protected $fillable = [
        'kode_klien',
        'nama_klien',
        'alias',
        'tipe_klien',
        'tipe_outlet',
        'stokis_area',
        'no_npwp',
        'perusahaan_id',
        'karyawan_ar_id',
        'resto_id',
        'status',
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

    public function karyawanAr()
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_ar_id');
    }

    public function resto()
    {
        return $this->belongsTo(Resto::class, 'resto_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'klien_ar_id');
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
