<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenancePlan extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'monthly_price_cents',
        'currency', 'included', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'monthly_price_cents' => 'integer',
        'included' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
