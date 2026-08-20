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
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'status',
    ];

    protected $hidden = [
        'password',
        'refresh_token',
        'smtp_password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status'   => 'boolean',
        ];
    }

    protected function smtpPassword(): Attribute
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

    /**
     * Kredensial SMTP lengkap milik user ini, atau null kalau belum di-setup
     * (host kosong dianggap belum di-setup meski field lain terisi sebagian).
     */
    public function hasSmtpConfigured(): bool
    {
        return filled($this->smtp_host) && filled($this->smtp_username) && filled($this->smtp_password);
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
