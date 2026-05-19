<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementDetail extends Model
{
    protected $table = 'tb_bank_statement_detail';

    protected $fillable = [
        'bank_statement_id',
        'tanggal',
        'keterangan',
        'debit',
        'kredit',
        'saldo',
        'status_cocok',
        'pembayaran_ar_id',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'debit'   => 'float',
        'kredit'  => 'float',
        'saldo'   => 'float',
    ];

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function pembayaranAr(): BelongsTo
    {
        return $this->belongsTo(PembayaranAr::class, 'pembayaran_ar_id');
    }
}
