<?php

namespace App\Models;

use App\Concerns\GeneratesUuid;
use App\Enums\QuoteItemType;
use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quote extends Model
{
    use GeneratesUuid;

    protected $fillable = [
        'lead_id', 'spec_id', 'maintenance_plan_id', 'uuid', 'number', 'version',
        'currency', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents',
        'deposit_percent', 'deposit_cents', 'maintenance_monthly_cents',
        'status', 'valid_until', 'notes', 'terms', 'pdf_path',
        'sent_at', 'accepted_at', 'rejected_at',
    ];

    protected $casts = [
        'status' => QuoteStatus::class,
        'version' => 'integer',
        'subtotal_cents' => 'integer',
        'discount_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'deposit_percent' => 'integer',
        'deposit_cents' => 'integer',
        'maintenance_monthly_cents' => 'integer',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(Spec::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    /** Recompute subtotal/discount/total/deposit from the line items. */
    public function recalculateTotals(): self
    {
        $items = $this->items()->get();

        $subtotal = (int) $items->where('type', '!=', QuoteItemType::Discount)->sum('total_cents');
        $discount = (int) abs($items->where('type', QuoteItemType::Discount)->sum('total_cents'));
        $total = max(0, $subtotal - $discount + $this->tax_cents);

        $this->subtotal_cents = $subtotal;
        $this->discount_cents = $discount;
        $this->total_cents = $total;
        $this->deposit_cents = $total > 0 ? max(1, (int) round($total * $this->deposit_percent / 100)) : 0;

        return $this;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
