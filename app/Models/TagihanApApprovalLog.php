<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagihanApApprovalLog extends Model
{
    protected $table = 'tb_tagihan_ap_approval_logs';

    protected $fillable = [
        'tagihan_ap_id',
        'action',
        'actor_id',
        'note',
    ];

    public function tagihanAp()
    {
        return $this->belongsTo(TagihanAp::class, 'tagihan_ap_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
