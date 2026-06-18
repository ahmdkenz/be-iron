<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndingBalanceKoreksi extends Model
{
    protected $table = 'tb_ending_balance_koreksi';

    protected $fillable = [
        'ending_balance_id',
        'klien_ar_id',
        'invoice_id',
        'nilai_koreksi',
        'alasan_koreksi',
        'dokumen_url',
        'status',
        'spv_id',
        'spv_note',
        'spv_actioned_at',
        'manager_id',
        'manager_note',
        'manager_actioned_at',
        'submitted_by',
        'submitted_at',
        'updated_by',
    ];

    protected $casts = [
        'nilai_koreksi'      => 'decimal:2',
        'spv_actioned_at'    => 'datetime',
        'manager_actioned_at'=> 'datetime',
        'submitted_at'       => 'datetime',
    ];

    public function endingBalance()
    {
        return $this->belongsTo(EndingBalance::class, 'ending_balance_id');
    }

    public function klienAr()
    {
        return $this->belongsTo(KlienAr::class, 'klien_ar_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function spv()
    {
        return $this->belongsTo(User::class, 'spv_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function isPendingSpv(): bool
    {
        return $this->status === 'PENDING_SPV';
    }

    public function isPendingManager(): bool
    {
        return $this->status === 'PENDING_MANAGER';
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
