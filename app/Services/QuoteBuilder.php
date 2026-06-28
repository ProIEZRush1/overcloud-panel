<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Enums\QuoteItemType;
use App\Enums\QuoteStatus;
use App\Models\Lead;
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

        $depositPercent = (int) Setting::get('default_deposit_percent', 40);
        $deployPercent = intdiv(100 - $depositPercent, 2);
        $finalPercent = 100 - $depositPercent - $deployPercent;

        $features = ServiceFeature::whereIn('id', $opts['feature_ids'] ?? [])->get();

        // Maintenance scales with complexity: the platform's base maintenance plus
        // each selected function's monthly maintenance. No fixed plans.
        $maintenance = (int) $service->base_maintenance_cents + (int) $features->sum('maintenance_cents');

        $quote = Quote::create([
            'lead_id' => $lead->id,
            'spec_id' => $spec?->id ?? $lead->latestSpec?->id,
            'maintenance_plan_id' => null,
            'number' => $this->nextNumber(),
            'currency' => 'MXN',
            'deposit_percent' => $depositPercent,
            'maintenance_monthly_cents' => $maintenance,
            'status' => QuoteStatus::Draft,
            'valid_until' => now()->addDays((int) Setting::get('quote_valid_days', 15)),
            'terms' => 'Forma de pago del proyecto (pago único): '.$depositPercent.'% para iniciar, '.$deployPercent.'% al desplegar el sitio y '.$finalPercent.'% en la entrega final. '
                .'El mantenimiento mensual es un servicio aparte y recurrente (ver el recuadro de mantenimiento), no forma parte del precio del proyecto. Precios en MXN.',
        ]);

        // Main service line.
        $quote->items()->create([
            'type' => QuoteItemType::Service,
            'service_id' => $service->id,
            'description' => $service->name.' — '.$pages.' página(s), '.$languages.' idioma(s)',
            'quantity' => 1,
            'unit_price_cents' => $service->priceForCents($pages, $languages),
        ]);

        // Functions / features.
        foreach ($features as $feature) {
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
        $lead->update(['stage' => LeadStage::Quoted]);

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
