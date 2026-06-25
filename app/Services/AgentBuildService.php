<?php

namespace App\Services;

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
        return $this->run($project, $dir, $this->buildPrompt($project, $stack, $content));
    }

    /** Repair a project that failed to build/serve, using the deploy logs. */
    public function repair(Project $project, string $stack, string $dir, string $logs): bool
    {
        return $this->run($project, $dir, $this->repairPrompt($stack, $logs));
    }

    private function run(Project $project, string $dir, string $prompt): bool
    {
        File::ensureDirectoryExists($dir);
        try {
            $r = Process::path($dir)->timeout((int) config('overcloud.deploy.build_timeout', 1500))
                ->run(['claude', '-p', $prompt, '--dangerously-skip-permissions', '--output-format', 'json']);
            if (! $r->successful()) {
                Log::warning('Claude build failed', ['project' => $project->id, 'err' => mb_substr($r->errorOutput(), 0, 300)]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Claude build threw', ['project' => $project->id, 'e' => $e->getMessage()]);

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

        return "Construye una APLICACIÓN WEB REAL Y FUNCIONAL (stack: {$stack}) para «{$who}» — NO una landing page. "
            .'Necesidad del cliente: '.($lead?->summary ?? 'plataforma profesional a la medida').".\n\n"
            .'IMPLEMENTA DE VERDAD estas funcionalidades del alcance aprobado (con su base de datos, panel de administración y lógica real):'
            .($features !== '' ? "\n- ".$features : "\n- ".json_encode($content['features'] ?? [], JSON_UNESCAPED_UNICODE))."\n\n"
            .'Si es una tienda: catálogo administrable, carrito, pagos con Stripe (claves por env), panel de administración y gestión de pedidos, todo funcional. '
            .'Moderno, responsivo, en español. OBLIGATORIO: footer "Desarrollado por Overcloud" enlazando https://wa.me/5215594356241, '
            .'y "¿Quieres tu sitio? Escríbenos por WhatsApp" al mismo enlace. '
            .'Incluye un Dockerfile que construya y sirva la app (bind 0.0.0.0, puerto correcto), con assets horneados para un build rápido, '
            .'un .dockerignore correcto, y migraciones+seed que se ejecuten al arrancar. Escribe TODOS los archivos en el directorio actual. '
            .'NO hagas git push ni despliegues: solo deja el proyecto listo para construir con Docker.';
    }

    private function repairPrompt(string $stack, string $logs): string
    {
        return "El despliegue de este proyecto ({$stack}) falló. Logs del build/runtime:\n"
            .mb_substr($logs, 0, 4000)."\n\n"
            .'Diagnostica la causa y CORRIGE los archivos del proyecto en el directorio actual para que el build de Docker '
            .'pase y la app sirva en su puerto. NO hagas push ni despliegues; solo corrige los archivos.';
    }
}
