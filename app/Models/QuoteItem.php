<?php

namespace App\Models;

use App\Enums\QuoteItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id', 'service_id', 'service_feature_id', 'type',
        'description', 'quantity', 'unit_price_cents', 'total_cents', 'sort_order',
    ];

    protected $casts = [
        'type' => QuoteItemType::class,
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'total_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (QuoteItem $item) {
            $item->total_cents = $item->quantity * $item->unit_price_cents;
        });
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceFeature(): BelongsTo
    {
        return $this->belongsTo(ServiceFeature::class);
    }
}
