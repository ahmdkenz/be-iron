<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndingBalance extends Model
{
    protected $table = 'tb_ending_balance';

    protected $fillable = [
        'klien_ar_id',
        'periode_awal',
        'periode_akhir',
        'saldo_awal',
        'invoice_masuk',
        'pembayaran',
        'saldo_akhir_sistem',
        'saldo_akhir_final',
        'status',
        'locked_by',
        'locked_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'periode_awal'       => 'date',
        'periode_akhir'      => 'date',
        'saldo_awal'         => 'decimal:2',
        'invoice_masuk'      => 'decimal:2',
        'pembayaran'         => 'decimal:2',
        'saldo_akhir_sistem' => 'decimal:2',
        'saldo_akhir_final'  => 'decimal:2',
        'locked_at'          => 'datetime',
    ];

    public function klienAr()
    {
        return $this->belongsTo(KlienAr::class, 'klien_ar_id');
    }

    public function koreksi()
    {
        return $this->hasMany(EndingBalanceKoreksi::class, 'ending_balance_id');
    }

    public function koreksiApproved()
    {
        return $this->hasMany(EndingBalanceKoreksi::class, 'ending_balance_id')
                    ->where('status', 'APPROVED');
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLocked(): bool
    {
        return $this->status === 'LOCKED';
    }

    public function hasActiveKoreksi(): bool
    {
        return $this->koreksi()
                    ->whereIn('status', ['PENDING_SPV', 'PENDING_MANAGER'])
                    ->exists();
    }
}
