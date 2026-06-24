<?php

namespace Database\Seeders;

use App\Models\BankAccount;
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

    /** Accessible pricing: 40% off the list value, rounded to a clean increment, in centavos. */
    private function cheaper(int|float $pesos, int $roundTo = 50): int
    {
        $discounted = round($pesos * 0.6 / $roundTo) * $roundTo;

        return (int) round($discounted * 100);
    }

    public function run(): void
    {
        $this->seedServices();
        $this->seedFunctions();
        $this->seedBank();
        $this->seedSettings();
    }

    /**
     * Base platform tiers. Price + base maintenance scale with complexity:
     * a landing is cheap with little maintenance; an app is expensive with much more.
     */
    private function seedServices(): void
    {
        $services = [
            ['key' => 'landing', 'name' => 'Landing Page', 'category' => 'web', 'sort_order' => 1,
                'description' => 'Una página de alto impacto para captar clientes.',
                'base_price' => 3500, 'base_maint' => 300, 'included_pages' => 1, 'per_page' => 900, 'per_lang' => 1200, 'timeline' => 5],
            ['key' => 'website', 'name' => 'Sitio Web', 'category' => 'web', 'sort_order' => 2,
                'description' => 'Sitio informativo de varias páginas con diseño profesional.',
                'base_price' => 7500, 'base_maint' => 500, 'included_pages' => 4, 'per_page' => 1200, 'per_lang' => 1500, 'timeline' => 12],
            ['key' => 'ecommerce', 'name' => 'Tienda en Línea', 'category' => 'ecommerce', 'sort_order' => 3,
                'description' => 'Tienda con catálogo, carrito y pagos.',
                'base_price' => 14000, 'base_maint' => 900, 'included_pages' => 6, 'per_page' => 1200, 'per_lang' => 2000, 'timeline' => 25],
            ['key' => 'webapp', 'name' => 'Aplicación Web', 'category' => 'app', 'sort_order' => 4,
                'description' => 'Aplicación web a la medida con lógica de negocio y panel.',
                'base_price' => 22000, 'base_maint' => 1400, 'included_pages' => 8, 'per_page' => 1800, 'per_lang' => 2500, 'timeline' => 40],
            ['key' => 'mobileapp', 'name' => 'Aplicación Móvil', 'category' => 'app', 'sort_order' => 5,
                'description' => 'App nativa/híbrida para iOS y Android.',
                'base_price' => 38000, 'base_maint' => 2200, 'included_pages' => 4, 'per_page' => 2000, 'per_lang' => 3000, 'timeline' => 55],
        ];

        foreach ($services as $s) {
            Service::updateOrCreate(['key' => $s['key']], [
                'name' => $s['name'], 'description' => $s['description'], 'category' => $s['category'],
                'base_price_cents' => $this->cheaper($s['base_price'], 100),
                'base_maintenance_cents' => $this->cheaper($s['base_maint'], 10),
                'included_pages' => $s['included_pages'],
                'per_page_price_cents' => $this->cheaper($s['per_page'], 50),
                'per_language_price_cents' => $this->cheaper($s['per_lang'], 50),
                'default_timeline_days' => $s['timeline'],
                'default_maintenance_plan_id' => null,
                'is_active' => true, 'sort_order' => $s['sort_order'],
            ]);
        }
    }

    /**
     * Function catalog. Each function adds a one-time BUILD price and a monthly
     * MAINTENANCE cost, so a project with more (and heavier) functions costs more
     * to build AND more to maintain. applies_to = null means "any project type".
     * Tuple: [key, name, category, build, maint, applies_to|null]
     */
    private function seedFunctions(): void
    {
        $functions = [
            // Diseño & UX
            ['diseno_premium', 'Diseño premium a la medida', 'Diseño', 4000, 250, null],
            ['animaciones', 'Animaciones e interacciones', 'Diseño', 2500, 150, null],
            ['modo_oscuro', 'Modo oscuro', 'Diseño', 1000, 60, ['website', 'ecommerce', 'webapp', 'mobileapp']],
            ['branding', 'Identidad visual / branding', 'Diseño', 3000, 0, null],

            // Contenido
            ['blog_cms', 'Blog / CMS administrable', 'Contenido', 2500, 180, ['website', 'ecommerce', 'webapp']],
            ['editor_contenido', 'Editor de contenido (autogestión)', 'Contenido', 3000, 200, ['website', 'ecommerce', 'webapp']],
            ['galeria', 'Galería multimedia / video', 'Contenido', 1500, 100, null],

            // E-commerce
            ['catalogo', 'Catálogo de productos', 'E-commerce', 3500, 250, ['ecommerce', 'webapp', 'mobileapp']],
            ['carrito', 'Carrito de compras', 'E-commerce', 3000, 200, ['ecommerce', 'webapp', 'mobileapp']],
            ['inventario', 'Gestión de inventario', 'E-commerce', 3500, 280, ['ecommerce', 'webapp']],
            ['cupones', 'Cupones y descuentos', 'E-commerce', 1500, 100, ['ecommerce']],
            ['envios', 'Cálculo de envíos / paquetería', 'E-commerce', 2500, 200, ['ecommerce']],
            ['wishlist', 'Lista de deseos', 'E-commerce', 1200, 80, ['ecommerce']],
            ['resenas', 'Reseñas y calificaciones', 'E-commerce', 1800, 120, ['ecommerce']],

            // Pagos
            ['pasarela_pago', 'Pasarela de pago (MercadoPago/Stripe)', 'Pagos', 3500, 300, ['website', 'ecommerce', 'webapp', 'mobileapp']],
            ['pagos_recurrentes', 'Suscripciones / pagos recurrentes', 'Pagos', 4000, 350, ['ecommerce', 'webapp', 'mobileapp']],
            ['facturacion_cfdi', 'Facturación CFDI (SAT)', 'Pagos', 5000, 400, ['ecommerce', 'webapp']],
            ['pagos_inapp', 'Pagos dentro de la app', 'Pagos', 5000, 400, ['mobileapp']],

            // Usuarios & acceso
            ['auth', 'Registro / inicio de sesión', 'Usuarios', 2500, 200, ['website', 'ecommerce', 'webapp', 'mobileapp']],
            ['sso', 'Inicio de sesión social (Google/Apple)', 'Usuarios', 1800, 100, ['ecommerce', 'webapp', 'mobileapp']],
            ['roles', 'Roles y permisos', 'Usuarios', 2500, 180, ['ecommerce', 'webapp', 'mobileapp']],
            ['perfiles', 'Perfiles de usuario', 'Usuarios', 2000, 130, ['ecommerce', 'webapp', 'mobileapp']],
            ['panel_admin', 'Panel de administración', 'Usuarios', 6000, 450, ['ecommerce', 'webapp', 'mobileapp']],

            // Comunicación
            ['whatsapp_integ', 'Integración WhatsApp', 'Comunicación', 2500, 180, null],
            ['chat_vivo', 'Chat en vivo', 'Comunicación', 2500, 200, null],
            ['notif_email', 'Notificaciones por correo', 'Comunicación', 1500, 100, null],
            ['notif_push', 'Notificaciones push', 'Comunicación', 2500, 180, ['webapp', 'mobileapp']],
            ['reservaciones', 'Reservaciones / agenda de citas', 'Comunicación', 4000, 300, ['website', 'ecommerce', 'webapp', 'mobileapp']],
            ['ia_chatbot', 'Chatbot con IA', 'Comunicación', 5000, 400, null],

            // Integraciones
            ['api_externa', 'Integración con API / sistema externo', 'Integraciones', 4000, 300, null],
            ['crm_erp', 'Integración con CRM / ERP', 'Integraciones', 4500, 350, ['ecommerce', 'webapp']],
            ['mapas', 'Mapas y geolocalización', 'Integraciones', 2000, 150, null],
            ['email_marketing', 'Email marketing (Mailchimp)', 'Integraciones', 1800, 120, null],

            // Datos & reportes
            ['dashboard', 'Tablero con métricas', 'Datos y reportes', 4500, 320, ['ecommerce', 'webapp', 'mobileapp']],
            ['reportes', 'Reportes y exportación (PDF/Excel)', 'Datos y reportes', 2500, 200, ['ecommerce', 'webapp']],
            ['buscador', 'Buscador avanzado / filtros', 'Datos y reportes', 2500, 180, ['ecommerce', 'webapp']],
            ['tiempo_real', 'Sincronización en tiempo real', 'Datos y reportes', 4500, 380, ['webapp', 'mobileapp']],
            ['multi_sucursal', 'Multi-sucursal / multi-tenant', 'Datos y reportes', 6000, 500, ['ecommerce', 'webapp']],

            // Móvil
            ['offline', 'Modo offline / PWA', 'Móvil', 3000, 220, ['webapp', 'mobileapp']],
            ['biometria', 'Acceso biométrico', 'Móvil', 2000, 120, ['mobileapp']],
            ['camara_qr', 'Cámara / escáner QR', 'Móvil', 2000, 130, ['mobileapp']],
            ['publicacion_stores', 'Publicación en App Store y Play Store', 'Móvil', 3500, 250, ['mobileapp']],

            // Marketing
            ['seo_avanzado', 'SEO avanzado', 'Marketing', 2500, 150, null],
            ['analitica', 'Analítica y eventos', 'Marketing', 1200, 80, null],
            ['redes_sociales', 'Integración con redes sociales', 'Marketing', 1500, 100, null],

            // Infraestructura
            ['dominio_correos', 'Dominio + correos profesionales (1 año)', 'Infraestructura', 1200, 120, null],
            ['hosting_premium', 'Hosting administrado premium', 'Infraestructura', 2000, 250, null],
            ['respaldos', 'Respaldos automáticos', 'Infraestructura', 1000, 90, null],
            ['seguridad', 'Seguridad reforzada (SSL/WAF)', 'Infraestructura', 1500, 120, null],
        ];

        foreach ($functions as $i => [$key, $name, $category, $build, $maint, $appliesTo]) {
            ServiceFeature::updateOrCreate(
                ['key' => $key, 'service_id' => null],
                [
                    'name' => $name,
                    'category' => $category,
                    'price_cents' => $this->cheaper($build, 50),
                    'maintenance_cents' => $this->cheaper($maint, 10),
                    'price_type' => 'flat',
                    'applies_to' => $appliesTo,
                    'is_active' => true,
                    'sort_order' => $i + 1,
                ]
            );
        }

        // Remove any legacy global functions no longer in the catalog.
        ServiceFeature::whereNull('service_id')
            ->whereNotIn('key', array_column($functions, 0))
            ->delete();
    }

    private function seedBank(): void
    {
        BankAccount::updateOrCreate(
            ['label' => 'Cuenta principal'],
            [
                'bank' => 'BBVA', 'beneficiary' => 'Eduardo', 'account_number' => '', 'clabe' => '',
                'currency' => 'MXN', 'is_active' => true, 'is_default' => true, 'sort_order' => 1,
                'instructions' => 'Envía tu comprobante por este chat una vez realizada la transferencia.',
            ]
        );
    }

    private function seedSettings(): void
    {
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
