<?php

namespace App\Services;

use App\Enums\QuoteItemType;
use App\Enums\QuoteStatus;
use App\Models\Lead;
use App\Models\MaintenancePlan;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceFeature;
use App\Models\Setting;
use App\Models\Spec;

class QuoteBuilder
{
    /**
     * Build a draft quote for a lead from its service, pages, languages and chosen add-ons.
     *
     * @param  array{feature_ids?: array<int>, pages?: int, languages?: int, discount_cents?: int}  $opts
     */
    public function buildFromLead(Lead $lead, ?Spec $spec = null, array $opts = []): Quote
    {
        $service = $lead->service ?? Service::where('key', 'website')->firstOrFail();
        $pages = $opts['pages'] ?? $lead->pages ?? $service->included_pages;
        $languages = $opts['languages'] ?? max(1, count($lead->languages ?? ['es']));

        $depositPercent = (int) (Setting::get('default_deposit_percent', 50));
        $plan = $lead->maintenancePlan ?? $service->defaultMaintenancePlan ?? MaintenancePlan::where('key', 'estandar')->first();

        $quote = Quote::create([
            'lead_id' => $lead->id,
            'spec_id' => $spec?->id ?? $lead->latestSpec?->id,
            'maintenance_plan_id' => $plan?->id,
            'number' => $this->nextNumber(),
            'currency' => 'MXN',
            'deposit_percent' => $depositPercent,
            'maintenance_monthly_cents' => $plan?->monthly_price_cents ?? 0,
            'status' => QuoteStatus::Draft,
            'valid_until' => now()->addDays((int) Setting::get('quote_valid_days', 15)),
            'terms' => 'Anticipo del '.$depositPercent.'% para iniciar; el resto contra entrega. Precios en MXN.',
        ]);

        // Main service line.
        $quote->items()->create([
            'type' => QuoteItemType::Service,
            'service_id' => $service->id,
            'description' => $service->name.' — '.$pages.' página(s), '.$languages.' idioma(s)',
            'quantity' => 1,
            'unit_price_cents' => $service->priceForCents($pages, $languages),
        ]);

        // Add-ons.
        foreach (ServiceFeature::whereIn('id', $opts['feature_ids'] ?? [])->get() as $feature) {
            $quote->items()->create([
                'type' => QuoteItemType::Feature,
                'service_id' => $service->id,
                'service_feature_id' => $feature->id,
                'description' => $feature->name,
                'quantity' => 1,
                'unit_price_cents' => $feature->price_cents,
            ]);
        }

        if (! empty($opts['discount_cents'])) {
            $quote->items()->create([
                'type' => QuoteItemType::Discount,
                'description' => 'Descuento',
                'quantity' => 1,
                'unit_price_cents' => -abs((int) $opts['discount_cents']),
            ]);
        }

        $quote->recalculateTotals()->save();
        $lead->update(['stage' => \App\Enums\LeadStage::Quoted]);

        return $quote->fresh('items');
    }

    private function nextNumber(): string
    {
        $prefix = Setting::get('quote_prefix', 'OVC');
        $year = now()->year;
        $seq = Quote::whereYear('created_at', $year)->count() + 1;

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }
}
