<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class Investor extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_investor';

    protected $fillable = [
        'nama_investor',
        'ktp',
        'npwp',
        'no_hp',
        'pengelola',
        'no_hp_pengelola',
        'alamat',
        'status',
        'keterangan',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function restos()
    {
        return $this->hasMany(Resto::class, 'investor_id');
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
