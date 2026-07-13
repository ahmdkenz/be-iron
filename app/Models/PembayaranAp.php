<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PembayaranAp extends Model
{
    use BlameableTrait;

    protected $table = 'tb_pembayaran_ap';

    protected $fillable = [
        'tagihan_ap_id',
        'tanggal_pembayaran',
        'jumlah_pembayaran',
        'metode_pembayaran',
        'no_referensi',
        'keterangan',
        'sumber_pembayaran_ap_id',
        'dibuat_dari_rekonsiliasi',
        'bukti_file_name',
        'bukti_file_size',
        'bukti_mime_type',
        'bukti_uploaded_at',
        'bukti_disk',
        'bukti_path',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_pembayaran'       => 'date',
        'jumlah_pembayaran'        => 'decimal:2',
        'bukti_uploaded_at'        => 'datetime',
        'dibuat_dari_rekonsiliasi' => 'boolean',
    ];

    public function tagihanAp()
    {
        return $this->belongsTo(TagihanAp::class, 'tagihan_ap_id');
    }

    public function sumberPembayaran()
    {
        return $this->belongsTo(PembayaranAp::class, 'sumber_pembayaran_ap_id');
    }

    public function alokasiKelebihan(): HasMany
    {
        return $this->hasMany(PembayaranAp::class, 'sumber_pembayaran_ap_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PembayaranApLog::class, 'pembayaran_ap_id');
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
