<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Enums\SpecStatus;
use App\Models\Lead;
use App\Models\Service;
use App\Models\Spec;

class SpecBuilder
{
    /** Build a draft scope document (alcance) for a lead. */
    public function buildFromLead(Lead $lead): Spec
    {
        $service = $lead->service ?? Service::where('key', 'website')->firstOrFail();
        $pages = $lead->pages ?? $service->included_pages;
        $languages = $lead->languages ?? ['es'];
        $version = ($lead->specs()->max('version') ?? 0) + 1;

        $content = [
            'service' => $service->name,
            'pages' => $this->defaultPages($service->key, $pages),
            'features' => $lead->requirements['features'] ?? $this->defaultFeatures($service->key),
            'languages' => $languages,
            'deliverables' => [
                'Diseño responsivo (móvil y escritorio)',
                'Implementación y publicación en línea',
                'Capacitación básica de uso',
            ],
            'timeline_days' => $service->default_timeline_days,
            'notes' => $lead->summary,
        ];

        $spec = $lead->specs()->create([
            'version' => $version,
            'title' => 'Alcance — '.$service->name.($lead->company ? ' · '.$lead->company : ''),
            'summary' => $lead->summary,
            'content' => $content,
            'status' => SpecStatus::Draft,
        ]);

        if ($lead->stage === LeadStage::New || $lead->stage === LeadStage::Qualifying) {
            $lead->update(['stage' => LeadStage::Spec]);
        }

        return $spec;
    }

    private function defaultPages(string $serviceKey, int $count): array
    {
        $base = match ($serviceKey) {
            'landing' => ['Inicio'],
            'ecommerce' => ['Inicio', 'Catálogo', 'Producto', 'Carrito', 'Pago', 'Contacto'],
            'webapp' => ['Inicio', 'Acceso', 'Panel', 'Perfil'],
            default => ['Inicio', 'Nosotros', 'Servicios', 'Contacto'],
        };

        return array_slice(array_pad($base, $count, 'Sección adicional'), 0, max($count, count($base)));
    }

    private function defaultFeatures(string $serviceKey): array
    {
        return match ($serviceKey) {
            'ecommerce' => ['Catálogo de productos', 'Carrito de compras', 'Pasarela de pago', 'Panel de administración'],
            'webapp' => ['Autenticación de usuarios', 'Panel de control', 'Lógica de negocio a la medida'],
            'landing' => ['Llamado a la acción', 'Formulario de contacto', 'Optimización de conversión'],
            default => ['Formulario de contacto', 'Integración con WhatsApp', 'Optimización básica SEO'],
        };
    }
}
