<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class Barang extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_barang';

    protected $fillable = [
        'kode_barang',
        'nama_barang',
        'spesifikasi',
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
}
