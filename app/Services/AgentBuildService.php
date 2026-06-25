<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * On-demand builder for the hybrid's escalation path: when the fast template path
 * doesn't fit (a project flagged custom, a novel stack, or a build that won't pass
 * E2E), Claude Code builds/repairs the project from scratch on the server. The
 * DeployService harness still owns push + deploy + verify-until-live around it.
 */
class AgentBuildService
{
    public function isAvailable(): bool
    {
        try {
            return trim((string) Process::timeout(15)->run(['bash', '-lc', 'command -v claude'])->output()) !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Build the project from scratch into $dir. Returns true on success. */
    public function build(Project $project, string $stack, array $content, string $dir): bool
    {
        return $this->run($project->id, $dir, $this->buildPrompt($project, $stack, $content));
    }

    /** Repair a project that failed to build/serve, using the deploy logs. */
    public function repair(Project $project, string $stack, string $dir, string $logs): bool
    {
        return $this->run($project->id, $dir, $this->repairPrompt($stack, $logs));
    }

    /** Apply a client-requested change to an existing project checkout. */
    public function change(Project $project, string $dir, string $instruction): bool
    {
        $prompt = 'Aplica EXACTAMENTE este cambio solicitado por el cliente al proyecto en el directorio actual: "'.$instruction.'". '
            .'Identifica con precisión el elemento exacto que menciona (p. ej. "botón flotante de WhatsApp" = el botón fijo/flotante de WhatsApp, normalmente abajo a la derecha) '
            .'y aplica el cambio en TODOS los archivos relevantes (HTML y CSS). Si es un color, cambia el valor del color de ESE elemento en el CSS. '
            .'Antes de terminar, VERIFICA leyendo los archivos que el cambio realmente quedó reflejado (busca el valor nuevo). '
            .'Conserva el resto del sitio funcionando, mantén el footer "Desarrollado por Overcloud", el Dockerfile y la configuración de despliegue. '
            .'Si el cambio afecta los assets (CSS/JS), reconstrúyelos para que queden horneados. NO hagas git push ni despliegues; solo edita los archivos.';

        return $this->run($project->id, $dir, $prompt);
    }

    /** Build a simple one-page visual demo for a lead (shown before the quote). */
    public function buildDemo(Lead $lead, string $dir): bool
    {
        $who = $lead->company ?: ($lead->name ?: 'el negocio');
        $spec = $lead->specs()->latest()->first();
        $features = collect($spec?->content['features'] ?? [])
            ->map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f)->filter()->implode(', ');
        $prompt = "Construye un DEMO visual de UNA sola página (index.html, styles.css y script.js si ayuda) para «{$who}». "
            .'Es un demo para que el cliente VEA cómo se vería su proyecto y se enamore — no necesita backend ni ser 100% funcional, pero debe verse increíble. '
            .'Necesidad del cliente: '.($lead->summary ?? 'sitio profesional').'. Muestra visualmente: '.($features !== '' ? $features : 'las secciones principales').'. '
            .'Diseño moderno, atractivo y responsivo, en español. '
            .$this->imageRules()
            .'OBLIGATORIO: footer "Desarrollado por Overcloud" enlazando https://wa.me/5215594356241. '
            .'Incluye un Dockerfile que sirva los archivos estáticos en el puerto 8080: FROM python:3-alpine / WORKDIR /app / COPY . /app / EXPOSE 8080 / CMD ["python","-m","http.server","8080"]. '
            .'Escribe TODOS los archivos COMPLETOS en el directorio actual AHORA, no expliques. NO hagas git push ni despliegues.';

        return $this->run($lead->id, $dir, $prompt);
    }

    private function run(int $id, string $dir, string $prompt): bool
    {
        File::ensureDirectoryExists($dir);
        @chmod($dir, 0777); // the non-root builder user must be able to write here
        try {
            // Claude Code refuses --dangerously-skip-permissions as root, so run it as the
            // non-root 'builder' user (created by the container entrypoint).
            $inner = 'cd '.escapeshellarg($dir).' && HOME=/home/builder claude -p '.escapeshellarg($prompt)
                .' --dangerously-skip-permissions --output-format json';
            $r = Process::timeout((int) config('overcloud.deploy.build_timeout', 1500))
                ->run(['su', 'builder', '-c', $inner]);
            if (! $r->successful()) {
                Log::warning('Claude build failed', ['id' => $id, 'err' => mb_substr($r->errorOutput() ?: $r->output(), 0, 400)]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Claude build threw', ['id' => $id, 'e' => $e->getMessage()]);

            return false;
        }
    }

    private function buildPrompt(Project $project, string $stack, array $content): string
    {
        $lead = $project->lead;
        $who = $lead?->company ?: ($lead?->name ?: 'el negocio');
        $spec = $lead?->specs()->latest()->first();
        $features = collect($spec?->content['features'] ?? [])
            ->map(fn ($f) => is_array($f) ? trim(($f['name'] ?? '').' — '.($f['desc'] ?? '')) : $f)
            ->filter()->implode("\n- ");

        return "Construye un SITIO WEB COMPLETO, PULIDO y DESPLEGABLE para «{$who}». "
            .'Necesidad del cliente: '.($lead?->summary ?? 'sitio profesional a la medida').".\n\n"
            .'Representa de verdad lo que ofrece el negocio. Si es una tienda: catálogo de productos con fotos y precios, vista de producto, '
            .'y un carrito funcional en JavaScript con totales en vivo y un flujo de checkout — con datos de ejemplo realistas del negocio. '
            ."Inspírate en estas funcionalidades del alcance:\n- ".($features !== '' ? $features : 'sitio profesional a la medida')."\n\n"
            .'REQUISITOS TÉCNICOS (cúmplelos para que despliegue sin fallar en UN solo intento): '
            .'Usa SOLO HTML + CSS + JavaScript puro (sin frameworks de backend, sin composer ni npm) para que sea autónomo, rápido y confiable. '
            .'Diseño moderno, responsivo y de buen gusto, en español. '
            .$this->imageRules()
            .'OBLIGATORIO: un footer en todas las páginas que diga "Desarrollado por Overcloud" donde "Overcloud" enlaza a https://wa.me/5215594356241, '
            .'y "¿Quieres tu sitio? Escríbenos por WhatsApp" al mismo enlace. '
            .'Incluye un Dockerfile sencillo que sirva los archivos estáticos en el puerto 8080 con bind 0.0.0.0, por ejemplo: '
            .'FROM python:3-alpine / WORKDIR /app / COPY . /app / EXPOSE 8080 / CMD ["python","-m","http.server","8080"]. '
            .'Escribe TODOS los archivos COMPLETOS en el directorio actual AHORA — no dejes nada a medias ni expliques. NO hagas git push ni despliegues.';
    }

    private function repairPrompt(string $stack, string $logs): string
    {
        return "El despliegue de este proyecto ({$stack}) falló. Logs del build/runtime:\n"
            .mb_substr($logs, 0, 4000)."\n\n"
            .'Diagnostica la causa y CORRIGE los archivos del proyecto en el directorio actual para que el build de Docker '
            .'pase y la app sirva en su puerto. NO hagas push ni despliegues; solo corrige los archivos.';
    }

    /** Image guidance: never random/mismatched stock; prefer on-theme gradients/emojis. */
    private function imageRules(): string
    {
        return 'IMÁGENES: NO uses picsum.photos ni fotos aleatorias o genéricas, y JAMÁS paisajes, ciudades, '
            .'monumentos o lugares ajenos al giro del negocio (nada de puentes, rascacielos u otros países). '
            .'Prioriza gradientes CSS, colores de marca, patrones y emojis/íconos temáticos grandes para fondos, '
            .'héroes y tarjetas (siempre cargan y siempre son del tema). Si de verdad necesitas una foto real, usa '
            .'https://source.unsplash.com/<ancho>x<alto>/?<palabras> con palabras MUY específicas del giro '
            .'(p. ej. una taquería: tacos,comida-mexicana; una cafetería: coffee,cafe). ';
    }
}
