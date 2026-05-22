<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PembayaranAr extends Model
{
    use BlameableTrait;

    protected $table = 'tb_pembayaran_ar';

    protected $fillable = [
        'invoice_id',
        'tanggal_pembayaran',
        'jumlah_pembayaran',
        'metode_pembayaran',
        'no_referensi',
        'keterangan',
        'sumber_pembayaran_ar_id',
        'bukti_gdrive_file_id',
        'bukti_gdrive_folder_id',
        'bukti_file_name',
        'bukti_file_size',
        'bukti_mime_type',
        'bukti_uploaded_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_pembayaran' => 'date',
        'jumlah_pembayaran'  => 'decimal:2',
        'bukti_uploaded_at'  => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function sumberPembayaran()
    {
        return $this->belongsTo(PembayaranAr::class, 'sumber_pembayaran_ar_id');
    }

    public function alokasiKelebihan(): HasMany
    {
        return $this->hasMany(PembayaranAr::class, 'sumber_pembayaran_ar_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function bankStatementDetail(): HasOne
    {
        return $this->hasOne(BankStatementDetail::class, 'pembayaran_ar_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PembayaranArLog::class, 'pembayaran_ar_id');
    }
}
