<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\LeadStage;
use App\Enums\QuoteItemType;
use App\Enums\QuoteStatus;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceFeature;
use App\Models\Setting;
use App\Models\Spec;
use Illuminate\Support\Facades\Log;

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

        // The AI prices the project from its REAL scope (complexity, integrations, multi-tenant/SaaS,
        // voice/telephony, panels…) for EVERY client — the flat per-service base drastically under-priced
        // big builds. Falls back to the catalog math only if the AI is unavailable.
        $ai = $this->aiPrice($lead, $spec ?? $lead->latestSpec, $pages, $languages);

        // Maintenance scales with complexity: the AI's monthly, or the platform base + per-function monthly.
        $maintenance = $ai['monthly_cents'] ?? ((int) $service->base_maintenance_cents + (int) $features->sum('maintenance_cents'));

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

        // Main service line — the AI's project price (already covers every function), or the catalog price.
        $quote->items()->create([
            'type' => QuoteItemType::Service,
            'service_id' => $service->id,
            'description' => $service->name.($ai ? ' a la medida' : '').' — '.$pages.' página(s), '.$languages.' idioma(s)',
            'quantity' => 1,
            'unit_price_cents' => $ai['project_cents'] ?? $service->priceForCents($pages, $languages),
        ]);

        // Per-function priced lines ONLY in catalog mode — the AI price already includes all the functions
        // (they're detailed in the alcance), so adding them again would double-charge.
        if (! $ai) {
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

    /**
     * Ask the AI to price the project from its REAL scope, for the Mexican market (MXN).
     * Returns ['project_cents' => int, 'monthly_cents' => int] or null (→ fall back to catalog pricing).
     */
    private function aiPrice(Lead $lead, ?Spec $spec, int $pages, int $languages): ?array
    {
        $assistant = app(Assistant::class);
        if (! $assistant->isEnabled()) {
            return null;
        }
        $feats = collect($spec?->content['features'] ?? [])
            ->map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f)->filter()->implode('; ');
        $prompt = 'Eres un cotizador SENIOR de software para el mercado MEXICANO. Estima el precio JUSTO, competitivo pero RENTABLE de ESTE proyecto según su complejidad y funciones REALES (en pesos MXN). '
            .'Considera: cantidad y dificultad de funciones, integraciones (telefonía/Twilio, voz con IA/ElevenLabs, pagos/Stripe/Mercado Pago, Google Calendar, WhatsApp, APIs externas), si es multi-tenant o SaaS por suscripción, paneles de administración, reportes, grabación/transcripción, etc. '
            .'Rangos de referencia (MXN): sitio informativo simple 5,000–15,000; tienda o app con CRUD y panel 18,000–45,000; sistema robusto / SaaS / multi-tenant / voz-IA / telefonía 60,000–400,000+ según el alcance. '
            .'"project" = pago ÚNICO de desarrollo. "monthly" = mensualidad (hosting + soporte; si es SaaS por suscripción o usa servicios recurrentes de terceros como telefonía o voz, cóbrala acorde; un sitio simple lleva mensualidad baja). '
            .'Responde SOLO con JSON, sin texto ni explicación: {"project": <entero MXN>, "monthly": <entero MXN>}.'
            ."\n\nProyecto: ".($spec?->title ?: ($lead->service?->name ?? 'proyecto'))
            ."\nNegocio: ".(string) ($lead->summary ?? $lead->company ?? '')
            ."\nPáginas/módulos: {$pages} · Idiomas: {$languages}"
            ."\nFunciones del alcance: ".($feats ?: '(no detalladas)');
        try {
            $raw = $assistant->complete($prompt);
            if (! preg_match('/\{.*\}/s', (string) $raw, $m) || ! is_array($d = json_decode($m[0], true))) {
                return null;
            }
            $project = (int) round((float) ($d['project'] ?? 0));
            $monthly = (int) round((float) ($d['monthly'] ?? 0));
            if ($project <= 0) {
                return null;
            }

            return ['project_cents' => $project * 100, 'monthly_cents' => max(0, $monthly) * 100];
        } catch (\Throwable $e) {
            Log::warning('aiPrice failed', ['lead' => $lead->id, 'e' => $e->getMessage()]);

            return null;
        }
    }

    private function nextNumber(): string
    {
        $prefix = Setting::get('quote_prefix', 'OVC');
        $year = now()->year;
        $seq = Quote::whereYear('created_at', $year)->count() + 1;

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }
}
