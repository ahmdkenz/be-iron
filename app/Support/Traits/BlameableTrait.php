<?php

namespace App\Support\Traits;

use Illuminate\Support\Facades\Auth;

trait BlameableTrait
{
    public static function bootBlameableTrait(): void
    {
        static::creating(function ($model) {
            if (Auth::check() && !$model->created_by) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }
}
