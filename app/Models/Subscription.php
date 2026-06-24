<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'project_id', 'maintenance_plan_id', 'status', 'monthly_price_cents', 'currency',
        'started_at', 'renews_at', 'last_paid_at', 'cancelled_at',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'monthly_price_cents' => 'integer',
        'started_at' => 'date',
        'renews_at' => 'date',
        'last_paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }
}
