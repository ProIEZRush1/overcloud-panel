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

        $content = [
            'service' => $service->name,
            'overview' => $this->overview($lead, $service),
            'objectives' => $this->objectives($key),
            'pages' => $this->detailedPages($key, $pages),
            'features' => $this->detailedFeatures($key),
            'languages' => $languages,
            'deliverables' => $this->deliverables($key),
            'technical' => $this->technical(),
            'process' => $this->process(),
            'out_of_scope' => $this->outOfScope(),
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

        if (in_array($lead->stage, [LeadStage::New, LeadStage::Qualifying], true)) {
            $lead->update(['stage' => LeadStage::Spec]);
        }

        return $spec;
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
                ['name' => 'Catálogo administrable', 'desc' => 'Alta, edición y baja de productos con fotos, precios, variantes e inventario desde tu panel.'],
                ['name' => 'Carrito y checkout', 'desc' => 'Proceso de compra completo con resumen, cantidades y confirmación.'],
                ['name' => 'Pago con tarjeta', 'desc' => 'Integración con pasarela segura (MercadoPago/Stripe) para cobrar en línea.'],
                ['name' => 'Gestión de pedidos', 'desc' => 'Panel para ver, dar seguimiento y actualizar el estado de cada pedido.'],
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
            $common[] = 'Tienda lista para recibir pedidos y pagos.';
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
