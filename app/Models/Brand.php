<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_brand';

    protected $fillable = [
        'kode_brand',
        'nama_brand',
        'keterangan',
        'status',
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

    public function restos()
    {
        return $this->hasMany(Resto::class, 'brand_id');
    }

    public function barangs()
    {
        return $this->hasMany(Barang::class, 'brand_id');
    }
}
