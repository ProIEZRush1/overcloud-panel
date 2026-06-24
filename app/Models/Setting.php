<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'is_encrypted'];

    protected $casts = [
        'value' => 'array',
        'is_encrypted' => 'boolean',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Cache::rememberForever("setting:{$key}", fn () => static::where('key', $key)->first());

        return $setting?->value ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general'): self
    {
        $setting = static::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        Cache::forget("setting:{$key}");

        return $setting;
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $s) => Cache::forget("setting:{$s->key}"));
        static::deleted(fn (Setting $s) => Cache::forget("setting:{$s->key}"));
    }
}
