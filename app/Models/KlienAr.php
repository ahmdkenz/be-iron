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
        'tipe_klien',
        'no_npwp',
        'no_wa',
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

    // Nomor kontak WA yang benar-benar dipakai untuk kirim tagihan. no_wa
    // sendiri adalah field fallback manual (lihat label "No. WhatsApp
    // (Fallback AR)" di Form.vue) — nomor riil investor RESTO justru ada di
    // no_hp_pengelola (no_hp investor hampir tidak pernah diisi).
    public function resolveContactPhone(): ?string
    {
        if ($this->tipe_klien === 'RESTO') {
            $investor = $this->relationLoaded('resto') && $this->resto
                && $this->resto->relationLoaded('investor')
                ? $this->resto->investor
                : null;

            return $investor?->no_hp ?: ($investor?->no_hp_pengelola ?: ($this->no_wa ?: null));
        }

        if ($this->tipe_klien === 'PT') {
            $perusahaan = $this->relationLoaded('perusahaan') ? $this->perusahaan : null;

            return $perusahaan?->no_telp ?: ($this->no_wa ?: null);
        }

        return $this->no_wa ?: null;
    }

    public function resolveDisplayLabel(): string
    {
        if ($this->tipe_klien === 'RESTO') {
            $resto      = $this->relationLoaded('resto') ? $this->resto : null;
            $investor   = $resto && $resto->relationLoaded('investor') ? $resto->investor : null;
            $primaryName = $investor->nama_investor ?? $this->nama_klien;

            $label = $primaryName;
            if ($resto?->nama_resto) {
                $label .= " - {$resto->nama_resto}";
            }
            $kode = $resto->kode_resto ?? $this->kode_klien;
            if ($kode) {
                $label .= " ({$kode})";
            }

            return $label;
        }

        $label   = $this->nama_klien;
        $perusahaan = $this->relationLoaded('perusahaan') ? $this->perusahaan : null;
        $entitas = $perusahaan?->nama_singkatan_perusahaan ?: $perusahaan?->nama_perusahaan;
        if ($entitas) {
            $label .= " - {$entitas}";
        }
        if ($this->kode_klien) {
            $label .= " ({$this->kode_klien})";
        }

        return $label;
    }

    public function resolveDisplaySubtitle(): string
    {
        $parts = array_filter([
            $this->tipe_klien,
            $this->kode_klien,
            $this->relationLoaded('karyawanAr') && $this->karyawanAr
                ? "PIC: {$this->karyawanAr->nama_karyawan}" : null,
        ]);

        return implode(' • ', $parts);
    }
}
