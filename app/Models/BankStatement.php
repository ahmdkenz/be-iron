<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'is_committed',
        'import_batch_id',
    ];

    protected $casts = [
        'periode_awal'  => 'date',
        'periode_akhir' => 'date',
        'total_kredit'  => 'float',
        'is_committed'  => 'boolean',
    ];

    /**
     * Statement yang masih "draft" (is_committed=false, sedang di-insert+
     * auto_matching di dalam transaksi BankStatementImportService::finalize())
     * wajib tidak pernah muncul di query manapun sampai transaksi commit dan
     * di-flip true. Bypass eksplisit lewat withoutGlobalScope('committed') hanya
     * untuk operasi internal yang harus tetap jalan terhadap statement draft
     * (lihat BankStatementService::refreshCounter()).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('committed', function (Builder $builder) {
            $builder->where('tb_bank_statement.is_committed', true);
        });
    }

    public function details(): HasMany
    {
        return $this->hasMany(BankStatementDetail::class, 'bank_statement_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(BankStatementImportBatch::class, 'import_batch_id');
    }
}
