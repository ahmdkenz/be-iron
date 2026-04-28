<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceApprovalLog extends Model
{
    protected $table = 'tb_invoice_approval_logs';

    protected $fillable = [
        'invoice_id',
        'action',
        'actor_id',
        'note',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
