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

        // The AI breaks the scope into FUNCTIONS and prices each one CHEAPLY, in the same low range as our
        // catalog (so a robust build is itemized affordably, never a scary lump sum). The base price stays
        // the catalog's per-service base. Falls back to the catalog features only if the AI is unavailable.
        $ai = $this->aiPrice($lead, $spec ?? $lead->latestSpec, $service, $pages, $languages);

        // Maintenance: the AI's (low) monthly, or the platform base + per-function monthly.
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

        // Main service line — ALWAYS the catalog base price for the project type (keeps things affordable).
        $quote->items()->create([
            'type' => QuoteItemType::Service,
            'service_id' => $service->id,
            'description' => $service->name.' — '.$pages.' página(s), '.$languages.' idioma(s)',
            'quantity' => 1,
            'unit_price_cents' => $service->priceForCents($pages, $languages),
        ]);

        // Function lines: the AI's itemized functions priced CHEAPLY (catalog tier), or the catalog add-ons.
        if ($ai && ! empty($ai['features'])) {
            foreach ($ai['features'] as $f) {
                $quote->items()->create([
                    'type' => QuoteItemType::Feature,
                    'service_id' => $service->id,
                    'description' => (string) $f['name'],
                    'quantity' => 1,
                    'unit_price_cents' => (int) $f['price_cents'],
                ]);
            }
        } else {
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
     * Ask the AI to itemize the project into FUNCTIONS and price each one CHEAPLY, in the same low range
     * as our catalog (Overcloud is the affordable option) — so even a robust build reads as an accessible
     * list of add-ons on top of the base, never an expensive lump sum.
     * Returns ['features' => [['name'=>string,'price_cents'=>int], …], 'monthly_cents' => int] or null.
     */
    private function aiPrice(Lead $lead, ?Spec $spec, Service $service, int $pages, int $languages): ?array
    {
        $assistant = app(Assistant::class);
        if (! $assistant->isEnabled()) {
            return null;
        }
        $feats = collect($spec?->content['features'] ?? [])
            ->map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f)->filter()->implode('; ');
        $base = number_format($service->base_price_cents / 100);
        $prompt = 'Eres el cotizador de Overcloud, una agencia MEXICANA ECONÓMICA (precios BAJOS para ganar clientes, en MXN). '
            ."El tipo de proyecto ({$service->name}) ya incluye un precio base de \${$base} MXN que se cobra aparte — NO lo incluyas. "
            .'Tu tarea: desglosar el alcance en FUNCIONES concretas y ponerle a CADA UNA un precio BARATO, en NUESTRO rango de catálogo: '
            .'funciones simples $600–$1,000 MXN; medias $1,000–$1,800; complejas o a la medida $1,800–$3,000 MÁXIMO por función. '
            .'NUNCA cobres miles de dólares ni decenas de miles por una función — somos baratos. Lista entre 5 y 15 funciones reales del alcance (login, roles, panel, reportes, módulos, integraciones, etc.). '
            .'"monthly" = mensualidad de hosting+soporte, BAJA: $300–$1,500 MXN según el tamaño. '
            .'Responde SOLO con JSON, sin texto: {"features":[{"name":"<función>","price":<MXN entero>}, …],"monthly":<MXN entero>}.'
            ."\n\nProyecto: ".($spec?->title ?: ($lead->service?->name ?? 'proyecto'))
            ."\nNegocio: ".(string) ($lead->summary ?? $lead->company ?? '')
            ."\nFunciones del alcance: ".($feats ?: '(dedúcelas del proyecto)');
        try {
            $raw = $assistant->complete($prompt);
            if (! preg_match('/\{.*\}/s', (string) $raw, $m) || ! is_array($d = json_decode($m[0], true))) {
                return null;
            }
            $features = collect($d['features'] ?? [])
                ->map(fn ($f) => [
                    'name' => trim((string) ($f['name'] ?? '')),
                    // Hard cap each function at $3,000 MXN so the AI can never make it expensive.
                    'price_cents' => min(300000, max(0, (int) round((float) ($f['price'] ?? 0)) * 100)),
                ])
                ->filter(fn ($f) => $f['name'] !== '' && $f['price_cents'] > 0)
                ->values()->all();
            if (empty($features)) {
                return null;
            }

            return ['features' => $features, 'monthly_cents' => max(0, (int) round((float) ($d['monthly'] ?? 0)) * 100)];
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
