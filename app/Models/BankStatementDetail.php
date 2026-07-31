<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementDetail extends Model
{
    protected $table = 'tb_bank_statement_detail';

    protected $fillable = [
        'bank_statement_id',
        'tanggal',
        'waktu_transaksi',
        'keterangan',
        'no_referensi',
        'debit',
        'kredit',
        'saldo',
        'status_cocok',
        'status_posting_2',
        'posted_at',
        'posted_by',
        'pembayaran_ar_id',
        'pembayaran_ap_id',
        'matched_by',
    ];

    protected $casts = [
        'tanggal'          => 'date',
        'waktu_transaksi'  => 'string',
        'posted_at'        => 'datetime',
        'debit'            => 'float',
        'kredit'           => 'float',
        'saldo'            => 'float',
    ];

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function pembayaranAr(): BelongsTo
    {
        return $this->belongsTo(PembayaranAr::class, 'pembayaran_ar_id');
    }

    public function pembayaranAp(): BelongsTo
    {
        return $this->belongsTo(PembayaranAp::class, 'pembayaran_ap_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Baris dengan pembayaran/voucher nyata terhubung, atau ditandai DIABAIKAN —
     * SELALU dipindahkan (reparent) apa adanya saat reupload rekening koran,
     * tidak pernah di-unmatch/dihapus (lihat BankStatementImportService::finalize()).
     */
    public function scopeCarryOverEligible(Builder $q): Builder
    {
        return $q->where(fn (Builder $q2) => $q2->whereNotNull('pembayaran_ar_id')->orWhereNotNull('pembayaran_ap_id')->orWhere('status_cocok', 'DIABAIKAN'));
    }
}
