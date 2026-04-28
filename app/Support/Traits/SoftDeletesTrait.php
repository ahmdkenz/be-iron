<?php

namespace App\Support\Traits;

use Illuminate\Database\Eloquent\SoftDeletes;

trait SoftDeletesTrait
{
    use SoftDeletes;

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', false);
    }
}
