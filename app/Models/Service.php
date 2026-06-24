<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'category',
        'base_price_cents', 'base_maintenance_cents', 'currency', 'included_pages',
        'per_page_price_cents', 'per_language_price_cents',
        'default_timeline_days', 'default_maintenance_plan_id',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'base_price_cents' => 'integer',
        'base_maintenance_cents' => 'integer',
        'included_pages' => 'integer',
        'per_page_price_cents' => 'integer',
        'per_language_price_cents' => 'integer',
        'default_timeline_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(ServiceFeature::class);
    }

    public function defaultMaintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'default_maintenance_plan_id');
    }

    /**
     * Price for the given number of pages and languages, in cents.
     */
    public function priceForCents(int $pages = 1, int $languages = 1): int
    {
        $extraPages = max(0, $pages - $this->included_pages);
        $extraLanguages = max(0, $languages - 1);

        return $this->base_price_cents
            + ($extraPages * $this->per_page_price_cents)
            + ($extraLanguages * $this->per_language_price_cents);
    }
}
