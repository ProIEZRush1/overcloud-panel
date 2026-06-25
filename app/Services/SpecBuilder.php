<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\LeadStage;
use App\Enums\SpecStatus;
use App\Models\Lead;
use App\Models\Service;
use App\Models\Spec;
use Illuminate\Support\Str;

/**
 * Builds a detailed scope document (alcance): executive overview, objectives,
 * pages + descriptions, functionality + descriptions, deliverables, technical
 * inclusions, process phases and out-of-scope notes. The overview is tailored by
 * Claude when available, with a solid template fallback.
 */
class SpecBuilder
{
    public function __construct(private Assistant $assistant) {}

    public function buildFromLead(Lead $lead): Spec
    {
        $service = $lead->service ?? Service::where('key', 'website')->firstOrFail();
        $key = $service->key;
        $pages = (int) ($lead->pages ?: $service->included_pages);
        $languages = $lead->languages ?: ['es'];
        $version = ($lead->specs()->max('version') ?? 0) + 1;

        // Claude writes the full professional, project-specific scope from the
        // conversation — nothing predefined. Hardcoded content is only a fallback.
        $content = $this->generateScope($lead, $service) ?? $this->fallbackScope($lead, $service, $pages);
        $content['service'] = $service->name;
        $content['languages'] = $languages;
        $content['timeline_days'] = $content['timeline_days'] ?? $service->default_timeline_days;
        $content['notes'] = $content['notes'] ?? $lead->summary;

        $spec = $lead->specs()->create([
            'version' => $version,
            'title' => 'Alcance — '.$service->name.($lead->company ? ' · '.$lead->company : ''),
            'summary' => $lead->summary,
            'content' => $content,
            'status' => SpecStatus::Draft,
        ]);

        if (in_array($lead->stage, [LeadStage::New, LeadStage::Qualifying], true)) {
            $lead->update(['stage' => LeadStage::Spec]);
        }

        return $spec;
    }

    /** Claude generates the entire scope (objectives, features, pages, deliverables…) for THIS project. */
    private function generateScope(Lead $lead, Service $service): ?array
    {
        if (! $this->assistant->isEnabled()) {
            return null;
        }
        $prompt = 'Eres consultor senior de Overcloud, una agencia que construye software REAL y profesional (no páginas simples). '
            .'Con base en la conversación con el cliente, define el ALCANCE detallado y PROFESIONAL del proyecto que se construirá de verdad. '
            .'Incluye TODO lo que un proyecto así necesita en serio: p.ej. una tienda en línea lleva catálogo administrable, carrito, pagos con tarjeta '
            .'(Stripe), panel de administración, gestión de pedidos, cuentas de cliente e inventario; una plataforma de gestión lleva su panel, roles, '
            .'reportes, etc. Adáptalo a ESTE negocio en específico, sé concreto. Responde ÚNICAMENTE con JSON válido (sin ```), en español, así: '
            .'{"overview":"<2-3 frases>","objectives":["..."],"pages":[{"name":"","desc":""}],"features":[{"name":"","desc":""}],'
            .'"deliverables":["..."],"technical":["..."],"process":["..."],"out_of_scope":["..."],"timeline_days":<entero de días>}.'
            ."\n\nTipo de proyecto: ".$service->name.'. Negocio: '.($lead->company ?: ($lead->name ?: 'sin nombre'))
            .".\nLo que pidió el cliente:\n".$this->conversationContext($lead);

        try {
            $arr = json_decode($this->extractJson($this->assistant->complete($prompt)) ?? '', true);

            return (is_array($arr) && ! empty($arr['features'])) ? $arr : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function conversationContext(Lead $lead): string
    {
        $conv = $lead->conversations()->where('is_group', false)->latest('updated_at')->first();
        $chat = $conv?->messages()->where('is_from_me', false)->latest()->take(12)->get()
            ->reverse()->pluck('body')->filter()->implode("\n") ?? '';

        return trim(($lead->summary ? 'Resumen: '.$lead->summary."\n\n" : '').$chat)
            ?: ($lead->summary ?: 'Proyecto profesional a la medida.');
    }

    private function extractJson(?string $s): ?string
    {
        if (! $s) {
            return null;
        }

        return preg_match('/\{.*\}/s', $s, $m) ? $m[0] : $s;
    }

    /** Deterministic fallback, only used if Claude is unavailable. */
    private function fallbackScope(Lead $lead, Service $service, int $pages): array
    {
        $key = $service->key;

        return [
            'overview' => $this->overview($lead, $service),
            'objectives' => $this->objectives($key),
            'pages' => $this->detailedPages($key, $pages),
            'features' => $this->detailedFeatures($key),
            'deliverables' => $this->deliverables($key),
            'technical' => $this->technical(),
            'process' => $this->process(),
            'out_of_scope' => $this->outOfScope(),
        ];
    }

    private function overview(Lead $lead, Service $service): string
    {
        $who = $lead->company ?: ($lead->name ?: 'tu negocio');
        $fallback = 'Desarrollaremos '.Str::lower($service->name).' profesional a la medida de '.$who.', '
            .'enfocada en '.($lead->summary ?: 'cumplir tus objetivos de negocio y dar una excelente experiencia a tus clientes').'. '
            .'La solución será moderna, rápida y segura, totalmente adaptable a celular y escritorio, y fácil de administrar por ti.';

        if (! $this->assistant->isEnabled()) {
            return $fallback;
        }
        try {
            $system = 'Eres consultor de proyectos en Overcloud. Escribe UN solo párrafo (3 a 4 frases) de resumen ejecutivo profesional para el alcance de un proyecto digital, en español, claro y concreto. '
                .'Habla de lo que se construirá y el valor para el cliente. Sin saludos, sin listas, sin precios.';
            $prompt = [[
                'role' => 'user',
                'content' => 'Proyecto: '.$service->name.'. Cliente: '.($lead->name ?: 'N/D').($lead->company ? ' ('.$lead->company.')' : '')
                    .'. Necesidad: '.($lead->summary ?: 'N/D').'. Páginas: '.($lead->pages ?: 'las necesarias')
                    .'. Idiomas: '.implode(', ', $lead->languages ?? ['es']).'.',
            ]];

            return $this->assistant->message($system, $prompt) ?: $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function objectives(string $key): array
    {
        return match ($key) {
            'ecommerce' => [
                'Vender en línea 24/7 con un proceso de compra simple y confiable.',
                'Mostrar tu catálogo de forma atractiva y fácil de navegar.',
                'Cobrar con tarjeta de forma segura y recibir los pedidos organizados.',
                'Que puedas administrar productos, precios e inventario tú mismo.',
            ],
            'webapp' => [
                'Automatizar y centralizar la operación de tu negocio en una plataforma.',
                'Dar acceso seguro a usuarios con distintos roles y permisos.',
                'Tener información y reportes en tiempo real para tomar decisiones.',
            ],
            'mobileapp' => [
                'Estar presente en el celular de tus clientes con una app a la medida.',
                'Ofrecer una experiencia rápida y nativa en Android e iOS.',
                'Publicar en App Store y Play Store.',
            ],
            'landing' => [
                'Captar clientes potenciales con una página enfocada en convertir.',
                'Comunicar tu propuesta de valor de forma clara y persuasiva.',
                'Medir resultados y facilitar el contacto.',
            ],
            default => [
                'Tener presencia profesional en internet que genere confianza.',
                'Comunicar tus servicios y facilitar el contacto con clientes.',
                'Una base sólida, rápida y lista para crecer.',
            ],
        };
    }

    private function detailedPages(string $key, int $count): array
    {
        $base = match ($key) {
            'landing' => [
                ['name' => 'Página única', 'desc' => 'Secciones de presentación, beneficios, testimonios, llamados a la acción y formulario de contacto, en una sola página.'],
            ],
            'ecommerce' => [
                ['name' => 'Inicio', 'desc' => 'Portada con productos destacados, banners promocionales y accesos rápidos a categorías.'],
                ['name' => 'Catálogo', 'desc' => 'Listado de productos con filtros por categoría, buscador y ordenamiento.'],
                ['name' => 'Ficha de producto', 'desc' => 'Fotos, descripción, variantes (talla/color), precio y botón de compra.'],
                ['name' => 'Carrito', 'desc' => 'Resumen de compra con cantidades, edición y cálculo de total.'],
                ['name' => 'Checkout / Pago', 'desc' => 'Datos de envío y pago con tarjeta de forma segura.'],
                ['name' => 'Mi cuenta', 'desc' => 'Registro/inicio de sesión e historial de pedidos del cliente.'],
                ['name' => 'Contacto', 'desc' => 'Formulario de contacto, ubicación y datos del negocio.'],
            ],
            'webapp' => [
                ['name' => 'Acceso', 'desc' => 'Inicio de sesión y registro seguro de usuarios.'],
                ['name' => 'Tablero', 'desc' => 'Panel principal con métricas y accesos a los módulos.'],
                ['name' => 'Módulos a la medida', 'desc' => 'Pantallas de la lógica de negocio acordada (gestión de datos, procesos, etc.).'],
                ['name' => 'Perfil y ajustes', 'desc' => 'Datos del usuario, roles y configuración.'],
            ],
            default => [
                ['name' => 'Inicio', 'desc' => 'Portada con tu propuesta de valor y llamados a la acción.'],
                ['name' => 'Nosotros', 'desc' => 'Historia, misión y diferenciadores del negocio.'],
                ['name' => 'Servicios', 'desc' => 'Detalle de lo que ofreces, con descripciones e imágenes.'],
                ['name' => 'Contacto', 'desc' => 'Formulario, mapa de ubicación y redes sociales.'],
            ],
        };

        // Pad with extra sections if the client wants more pages than the base set.
        for ($i = count($base); $i < $count; $i++) {
            $base[] = ['name' => 'Sección adicional '.($i - count($base) + 1), 'desc' => 'Sección extra a definir según tu contenido.'];
        }

        return $base;
    }

    private function detailedFeatures(string $key): array
    {
        return match ($key) {
            'ecommerce' => [
                ['name' => 'Catálogo de productos', 'desc' => 'Productos con fotos, descripción, variantes (talla/color), categorías, búsqueda y filtros.'],
                ['name' => 'Carrito de compras', 'desc' => 'Agregar, actualizar y quitar productos con cálculo de totales en tiempo real.'],
                ['name' => 'Pagos con tarjeta (Stripe)', 'desc' => 'Cobro en línea seguro con tarjeta de crédito/débito mediante Stripe.'],
                ['name' => 'Panel de administración', 'desc' => 'Administra productos, categorías, inventario, precios y pedidos desde un panel privado.'],
                ['name' => 'Gestión de pedidos', 'desc' => 'Recibe pedidos, controla su estado y da seguimiento a cada venta.'],
                ['name' => 'Cuentas de cliente', 'desc' => 'Registro, inicio de sesión e historial de compras de tus clientes.'],
                ['name' => 'Control de inventario', 'desc' => 'Stock por producto que se descuenta automáticamente con cada venta.'],
                ['name' => 'Notificaciones', 'desc' => 'Confirmación de pedido al cliente por correo y/o WhatsApp.'],
            ],
            'webapp' => [
                ['name' => 'Autenticación y roles', 'desc' => 'Inicio de sesión seguro con distintos permisos por tipo de usuario.'],
                ['name' => 'Lógica de negocio a la medida', 'desc' => 'Módulos y procesos construidos según tu operación.'],
                ['name' => 'Reportes y métricas', 'desc' => 'Tableros con la información clave de tu negocio.'],
                ['name' => 'Panel de administración', 'desc' => 'Gestión de usuarios, datos y configuración.'],
            ],
            'mobileapp' => [
                ['name' => 'App nativa (Android/iOS)', 'desc' => 'Aplicación rápida y fluida para ambos sistemas.'],
                ['name' => 'Cuentas de usuario', 'desc' => 'Registro, inicio de sesión y perfiles.'],
                ['name' => 'Notificaciones push', 'desc' => 'Avisos directos al celular del usuario.'],
                ['name' => 'Publicación en tiendas', 'desc' => 'Gestión de la publicación en App Store y Play Store.'],
            ],
            'landing' => [
                ['name' => 'Diseño orientado a conversión', 'desc' => 'Estructura pensada para que el visitante realice la acción deseada.'],
                ['name' => 'Formulario de contacto / leads', 'desc' => 'Captura de datos de prospectos directo a tu correo o WhatsApp.'],
                ['name' => 'Analítica', 'desc' => 'Medición de visitas y eventos para optimizar resultados.'],
            ],
            default => [
                ['name' => 'Formulario de contacto', 'desc' => 'Recibe mensajes de tus clientes directo a tu correo.'],
                ['name' => 'Integración con WhatsApp', 'desc' => 'Botón para que te escriban con un clic.'],
                ['name' => 'SEO básico', 'desc' => 'Configuración inicial para aparecer en buscadores.'],
            ],
        };
    }

    private function deliverables(string $key): array
    {
        $common = [
            'Diseño a la medida alineado a tu marca (logo y colores).',
            'Sitio/aplicación funcional y publicado en línea.',
            'Versión adaptable a celular, tablet y escritorio.',
            'Capacitación básica para que administres tu plataforma.',
            'Soporte de arranque tras el lanzamiento.',
        ];
        if ($key === 'ecommerce') {
            $common[] = 'Tienda en línea funcional con panel de administración, pagos con tarjeta (Stripe) y gestión de pedidos.';
            $common[] = 'Accesos al panel de administración para gestionar tu catálogo, inventario y ventas.';
        }
        if ($key === 'mobileapp') {
            $common[] = 'App publicada (o lista para publicar) en las tiendas.';
        }

        return $common;
    }

    private function technical(): array
    {
        return [
            'Diseño responsivo (celular, tablet y escritorio).',
            'Hosting administrado y dominio configurado.',
            'Certificado de seguridad SSL (https).',
            'Optimización básica de velocidad de carga.',
            'Optimización básica para buscadores (SEO on-page).',
            'Respaldo inicial y puesta en producción.',
        ];
    }

    private function process(): array
    {
        return [
            'Descubrimiento — confirmamos requerimientos, contenidos y materiales.',
            'Diseño — propuesta visual alineada a tu marca para tu aprobación.',
            'Desarrollo — construcción de las páginas y funciones del alcance.',
            'Pruebas — revisión de calidad en distintos dispositivos y navegadores.',
            'Lanzamiento — publicación, capacitación y entrega final.',
        ];
    }

    private function outOfScope(): array
    {
        return [
            'Creación de contenido o fotografía profesional (se puede contratar aparte).',
            'Campañas de publicidad pagada y gestión de redes sociales.',
            'Funciones no listadas en este alcance (se cotizan por separado).',
        ];
    }
}
