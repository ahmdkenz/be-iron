<?php

namespace App\Models;
use App\Support\Traits\BlameableTrait;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
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
            'password' => 'hashed',
            'status'   => 'boolean',
        ];
    }

    protected function fonnteToken(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if ($value === null) {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    return null;
                }
            },
            set: fn (?string $value) => $value === null ? null : Crypt::encryptString($value),
        );
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
