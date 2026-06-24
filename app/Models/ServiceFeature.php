<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFeature extends Model
{
    protected $fillable = [
        'service_id', 'key', 'name', 'description', 'category',
        'price_cents', 'maintenance_cents', 'price_type', 'applies_to', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'maintenance_cents' => 'integer',
        'applies_to' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isGlobal(): bool
    {
        return $this->service_id === null;
    }
}
