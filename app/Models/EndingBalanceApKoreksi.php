<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndingBalanceApKoreksi extends Model
{
    protected $table = 'tb_ending_balance_ap_koreksi';

    protected $fillable = [
        'ending_balance_ap_id',
        'vendor_ap_id',
        'tagihan_ap_id',
        'tipe',
        'no_dokumen',
        'nilai_koreksi',
        'alasan_koreksi',
        'dokumen_url',
        'status',
        'approved_by',
        'approved_note',
        'approved_at',
        'submitted_by',
        'submitted_at',
        'updated_by',
    ];

    protected $casts = [
        'nilai_koreksi' => 'decimal:2',
        'approved_at'   => 'datetime',
        'submitted_at'  => 'datetime',
    ];

    public function endingBalanceAp()
    {
        return $this->belongsTo(EndingBalanceAp::class, 'ending_balance_ap_id');
    }

    public function vendorAp()
    {
        return $this->belongsTo(VendorAp::class, 'vendor_ap_id');
    }

    public function tagihanAp()
    {
        return $this->belongsTo(TagihanAp::class, 'tagihan_ap_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(EndingBalanceApKoreksiItem::class, 'ending_balance_ap_koreksi_id');
    }

    public function isCreditNote(): bool
    {
        return $this->tipe === 'CREDIT_NOTE';
    }

    public function isDebitNote(): bool
    {
        return $this->tipe === 'DEBIT_NOTE';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }
}
