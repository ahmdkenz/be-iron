<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BulkPrintToken extends Model
{
    use HasUuids;

    protected $table = 'tb_bulk_print_tokens';

    protected $primaryKey = 'token';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'token',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
