<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait GeneratesUuid
{
    public static function bootGeneratesUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
