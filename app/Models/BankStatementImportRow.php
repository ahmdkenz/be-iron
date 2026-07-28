<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementImportRow extends Model
{
    public $timestamps = false;

    protected $table = 'tb_bank_statement_import_rows';

    protected $fillable = [
        'batch_id',
        'row_number',
        'tanggal',
        'waktu_transaksi',
        'keterangan',
        'no_referensi',
        'debit',
        'kredit',
        'saldo',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'tanggal'    => 'date',
        'debit'      => 'float',
        'kredit'     => 'float',
        'saldo'      => 'float',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BankStatementImportBatch::class, 'batch_id');
    }
}
