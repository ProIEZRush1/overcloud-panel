<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\MaintenancePlan;
use App\Models\Service;
use App\Models\ServiceFeature;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /** Pesos → centavos. */
    private function mxn(int|float $pesos): int
    {
        return (int) round($pesos * 100);
    }

    public function run(): void
    {
        // ---- Maintenance plans (monthly, paid by transfer) ----
        $plans = [
            ['key' => 'basico', 'name' => 'Mantenimiento Básico', 'monthly_price_cents' => $this->mxn(499), 'sort_order' => 1,
                'description' => 'Cambios menores, corrección de bugs y respaldo mensual.',
                'included' => ['Cambios de texto e imágenes', 'Corrección de errores menores', 'Respaldo mensual', 'Soporte por WhatsApp']],
            ['key' => 'estandar', 'name' => 'Mantenimiento Estándar', 'monthly_price_cents' => $this->mxn(999), 'sort_order' => 2,
                'description' => 'Todo lo del Básico más actualizaciones y mejoras pequeñas.',
                'included' => ['Todo lo del plan Básico', 'Actualización de librerías y seguridad', 'Mejoras pequeñas mensuales', 'Monitoreo de disponibilidad']],
            ['key' => 'premium', 'name' => 'Mantenimiento Premium', 'monthly_price_cents' => $this->mxn(1999), 'sort_order' => 3,
                'description' => 'Atención prioritaria y horas de desarrollo incluidas.',
                'included' => ['Todo lo del plan Estándar', 'Atención prioritaria', '2 horas de desarrollo al mes', 'Reportes de rendimiento']],
        ];
        foreach ($plans as $p) {
            MaintenancePlan::updateOrCreate(['key' => $p['key']], $p);
        }
        $estandar = MaintenancePlan::where('key', 'estandar')->first();

        // ---- Services (base catalog, prices are placeholders to tune in the panel) ----
        $services = [
            ['key' => 'landing', 'name' => 'Landing Page', 'category' => 'landing',
                'description' => 'Una sola página de alto impacto para captar clientes.',
                'base_price_cents' => $this->mxn(3500), 'included_pages' => 1,
                'per_page_price_cents' => $this->mxn(900), 'per_language_price_cents' => $this->mxn(1200),
                'default_timeline_days' => 5, 'sort_order' => 1],
            ['key' => 'website', 'name' => 'Sitio Web', 'category' => 'website',
                'description' => 'Sitio web informativo de varias páginas con diseño profesional.',
                'base_price_cents' => $this->mxn(7500), 'included_pages' => 4,
                'per_page_price_cents' => $this->mxn(1200), 'per_language_price_cents' => $this->mxn(1500),
                'default_timeline_days' => 12, 'sort_order' => 2],
            ['key' => 'ecommerce', 'name' => 'Tienda en Línea', 'category' => 'ecommerce',
                'description' => 'Tienda e-commerce con catálogo, carrito y pagos.',
                'base_price_cents' => $this->mxn(15000), 'included_pages' => 6,
                'per_page_price_cents' => $this->mxn(1200), 'per_language_price_cents' => $this->mxn(2500),
                'default_timeline_days' => 25, 'sort_order' => 3],
            ['key' => 'webapp', 'name' => 'Aplicación Web', 'category' => 'webapp',
                'description' => 'Aplicación web a la medida con panel y lógica de negocio.',
                'base_price_cents' => $this->mxn(25000), 'included_pages' => 8,
                'per_page_price_cents' => $this->mxn(1800), 'per_language_price_cents' => $this->mxn(3000),
                'default_timeline_days' => 40, 'sort_order' => 4],
        ];
        foreach ($services as $s) {
            $s['default_maintenance_plan_id'] = $estandar?->id;
            Service::updateOrCreate(['key' => $s['key']], $s);
        }

        // ---- Global add-ons (offered for any service) ----
        $features = [
            ['key' => 'dominio_correo', 'name' => 'Dominio + correos', 'price_cents' => $this->mxn(1200), 'sort_order' => 1,
                'description' => 'Registro de dominio y configuración de correos profesionales por 1 año.'],
            ['key' => 'seo_basico', 'name' => 'SEO básico', 'price_cents' => $this->mxn(1800), 'sort_order' => 2,
                'description' => 'Optimización inicial para buscadores y metadatos.'],
            ['key' => 'blog_cms', 'name' => 'Blog / CMS', 'price_cents' => $this->mxn(2500), 'sort_order' => 3,
                'description' => 'Gestor de contenidos para publicar y editar notas.'],
            ['key' => 'pasarela_pago', 'name' => 'Pasarela de pago', 'price_cents' => $this->mxn(3500), 'sort_order' => 4,
                'description' => 'Integración de pagos (MercadoPago / Stripe).'],
            ['key' => 'whatsapp_integracion', 'name' => 'Integración WhatsApp', 'price_cents' => $this->mxn(2000), 'sort_order' => 5,
                'description' => 'Botón y automatización de contacto por WhatsApp.'],
            ['key' => 'diseno_premium', 'name' => 'Diseño premium', 'price_cents' => $this->mxn(4000), 'sort_order' => 6,
                'description' => 'Diseño visual a la medida con animaciones.'],
        ];
        foreach ($features as $f) {
            $f['service_id'] = null;
            ServiceFeature::updateOrCreate(['key' => $f['key'], 'service_id' => null], $f);
        }

        // ---- Default bank account (placeholder — fill in real details in the panel) ----
        BankAccount::updateOrCreate(
            ['label' => 'Cuenta principal'],
            [
                'bank' => 'BBVA', 'beneficiary' => 'Eduardo', 'account_number' => '', 'clabe' => '',
                'currency' => 'MXN', 'is_active' => true, 'is_default' => true, 'sort_order' => 1,
                'instructions' => 'Envía tu comprobante por este chat una vez realizada la transferencia.',
            ]
        );

        // ---- Settings: branding, pricing, AI ----
        Setting::put('company_name', 'Overcloud', 'branding');
        Setting::put('brand_primary', '#4f46e5', 'branding');
        Setting::put('brand_accent', '#0ea5e9', 'branding');
        Setting::put('quote_prefix', 'OVC', 'branding');
        Setting::put('currency', 'MXN', 'pricing');
        Setting::put('tax_percent', 0, 'pricing');
        Setting::put('default_deposit_percent', 50, 'pricing');
        Setting::put('quote_valid_days', 15, 'pricing');
        Setting::put('ai_model', 'claude-opus-4-8', 'ai');
        Setting::put('ai_locale', 'es', 'ai');
    }
}
