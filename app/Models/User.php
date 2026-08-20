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

    // model_has_permissions sengaja dihapus (migration 2026_08_03_104519) karena fitur
    // permission granular Spatie tidak dipakai — override no-op ini mencegah HasRoles'
    // bawaan HasPermissions trait mencoba DELETE ke tabel yang sudah tidak ada saat user dihapus.
    public static function bootHasPermissions()
    {
    }

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
        'smtp_from_email',
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
     * Wajib juga punya alamat "From" yang valid (lihat resolveSmtpFromEmail())
     * — banyak provider SMTP (mis. Mailtrap, SendGrid) pakai username berupa
     * token/API key, BUKAN alamat email, jadi tidak bisa dipakai sebagai From.
     */
    public function hasSmtpConfigured(): bool
    {
        return filled($this->smtp_host)
            && filled($this->smtp_username)
            && filled($this->smtp_password)
            && $this->resolveSmtpFromEmail() !== null;
    }

    /**
     * Alamat "From" untuk email yang dikirim lewat SMTP user ini. `smtp_username`
     * HANYA dipakai sebagai fallback kalau kebetulan sudah berbentuk email valid
     * (kasus umum: Gmail, di mana username = alamat email) — untuk provider lain
     * (Mailtrap, SendGrid, dsb. yang username-nya token/API key) WAJIB isi
     * `smtp_from_email` secara eksplisit, kalau tidak dianggap belum siap kirim.
     */
    public function resolveSmtpFromEmail(): ?string
    {
        $candidate = $this->smtp_from_email ?: $this->smtp_username;

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : null;
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
