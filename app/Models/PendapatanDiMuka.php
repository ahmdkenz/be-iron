<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendapatanDiMuka extends Model
{
    use BlameableTrait;

    protected $table = 'tb_pendapatan_di_muka';

    protected $fillable = [
        'sumber_pembayaran_ar_id',
        'bank_statement_detail_id',
        'investor_id',
        'klien_ar_id',
        'jumlah',
        'tanggal_pencatatan',
        'keterangan',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_pencatatan' => 'date',
        'jumlah'             => 'decimal:2',
    ];

    public function sumberPembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranAr::class, 'sumber_pembayaran_ar_id');
    }

    public function bankStatementDetail(): BelongsTo
    {
        return $this->belongsTo(BankStatementDetail::class, 'bank_statement_detail_id');
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class, 'investor_id');
    }

    public function klienAr(): BelongsTo
    {
        return $this->belongsTo(KlienAr::class, 'klien_ar_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
