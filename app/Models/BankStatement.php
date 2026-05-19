<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    protected $table = 'tb_bank_statement';

    protected $fillable = [
        'bank_type',
        'nama_file',
        'periode_awal',
        'periode_akhir',
        'total_transaksi',
        'total_kredit',
        'jumlah_matched',
        'jumlah_unmatched',
        'uploaded_by',
    ];

    protected $casts = [
        'periode_awal'  => 'date',
        'periode_akhir' => 'date',
        'total_kredit'  => 'float',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(BankStatementDetail::class, 'bank_statement_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
