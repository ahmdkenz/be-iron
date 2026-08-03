<?php

namespace App\Models;
use App\Support\Traits\BlameableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles, BlameableTrait;

    protected $table = 'tb_users';

    protected $fillable = [
        'username',
        'email',
        'password',
        'karyawan_id',
        'refresh_token',
        'no_hp',
        'fonnte_token',
        'status',
    ];

    protected $hidden = [
        'password',
        'refresh_token',
        'fonnte_token',
    ];

    protected function casts(): array
    {
        return [
            'password'     => 'hashed',
            'status'       => 'boolean',
            'fonnte_token' => 'encrypted',
        ];
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id');
    }

    public function getNameAttribute(): ?string
    {
        return $this->karyawan?->nama_karyawan ?? $this->username;
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
