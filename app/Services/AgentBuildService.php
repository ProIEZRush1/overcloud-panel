<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * On-demand builder. Instead of a one-shot `claude -p`, it opens a full agentic
 * Claude Code session in the build dir: a rich CLAUDE.md gives it the whole project
 * context (business, scope, brand + image rules) and a VERIFICATION PROTOCOL — the
 * agent builds/edits, serves the site locally (php -S), checks it with curl, fixes,
 * and only finishes once everything (and any requested change) is verified. The
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

    /** Build the project from scratch into $dir. */
    public function build(Project $project, string $stack, array $content, string $dir): bool
    {
        return $this->run($project->id, $dir, $this->context($project->lead, 'build'),
            'Construye el SITIO WEB COMPLETO, pulido y desplegable siguiendo CLAUDE.md. Crea todas las páginas necesarias con contenido realista del negocio '
            .'(si es tienda: catálogo con precios y carrito funcional en JS). Sigue el PROTOCOLO DE VERIFICACIÓN antes de terminar.');
    }

    /** Repair a project that failed to build/serve, using the deploy logs. */
    public function repair(Project $project, string $stack, string $dir, string $logs): bool
    {
        return $this->run($project->id, $dir, $this->context($project->lead, 'repair'),
            "El despliegue falló. Logs del build/runtime:\n".mb_substr($logs, 0, 4000)
            ."\n\nDiagnostica la causa, CORRIGE los archivos y verifica (PROTOCOLO DE VERIFICACIÓN de CLAUDE.md) que ya sirve bien.");
    }

    /**
     * Build a REAL Laravel + Vue (Inertia) app from the cloned template: the scope's modules become
     * actual migrations/models/controllers + Inertia Vue pages behind login, with an admin panel and
     * Postgres-backed (permanent) data. $admin = ['email'=>..,'password'=>..] is seeded.
     */
    public function buildFullstack(Project $project, string $dir, array $admin): bool
    {
        return $this->run($project->id, $dir, $this->context($project->lead, 'fullstack') + ['admin' => $admin],
            'Esta carpeta YA es una app Laravel 13 + Inertia + Vue funcionando (con login/registro y base de datos). NO la rehagas desde cero: '
            .'EXTIÉNDELA para convertirla en el sistema del cliente siguiendo CLAUDE.md. Implementa CADA módulo del alcance como funcionalidad REAL de Laravel: '
            .'migración + modelo Eloquent + controlador + rutas protegidas por auth + páginas Inertia/Vue (resources/js/Pages) con tablas, formularios CRUD, búsqueda y filtros. '
            .'Crea un PANEL DE ADMINISTRACIÓN (dashboard tras login) con navegación a todos los módulos. Los datos DEBEN guardarse en la base de datos (persistentes), NUNCA en localStorage. '
            .'Siembra el usuario admin indicado en el DatabaseSeeder. Corre las migraciones, `npm run build`, y SIGUE EL PROTOCOLO DE VERIFICACIÓN: levanta la app, regístrate/inicia sesión y confirma que cada módulo guarda y lee datos reales. No termines hasta verificarlo.');
    }

    /** Repair a full-stack Laravel+Vue build that failed to deploy/run, using the deploy logs. */
    public function repairFullstack(Project $project, string $dir, string $logs, array $admin): bool
    {
        return $this->run($project->id, $dir, $this->context($project->lead, 'fullstack') + ['admin' => $admin],
            "El despliegue de la app Laravel+Vue FALLÓ o no responde. Logs del build/runtime:\n".mb_substr($logs, 0, 4000)
            ."\n\nDiagnostica y CORRIGE la causa en esta app Laravel+Vue (errores de migración/compatibilidad Postgres, assets sin compilar, rutas, controladores, etc.). "
            .'Recompila assets (`npm run build`), valida migraciones (`php artisan migrate:fresh --seed` en sqlite) y vuelve a correr las pruebas Pest y de navegador del PROTOCOLO DE VERIFICACIÓN. No termines hasta que TODO pase.');
    }

    /** Apply a client-requested change to an existing project checkout. */
    public function change(Project $project, string $dir, string $instruction): bool
    {
        return $this->run($project->id, $dir, $this->context($project->lead, 'change'),
            'Aplica este cambio solicitado por el cliente: "'.$instruction.'". '
            .'Sigue el PROTOCOLO DE VERIFICACIÓN de CLAUDE.md: primero EXPLORA el repo (ls/grep/cat) para ubicar el elemento EXACTO, aplícalo en todos los archivos relevantes, '
            .'y CONFIRMA sirviendo el sitio que el cambio realmente quedó reflejado. No termines hasta verificarlo.');
    }

    /** Build a complete, representative visual demo for a lead (shown before the quote). */
    public function buildDemo(Lead $lead, string $dir): bool
    {
        return $this->run($lead->id, $dir, $this->context($lead, 'demo'),
            'Construye un DEMO COMPLETO y representativo del proyecto siguiendo CLAUDE.md — NO un boceto básico de una sola sección. '
            .'Refleja TODO lo del alcance: si es una app, muestra sus pantallas y funciones principales (varias vistas enlazadas); si es un sitio o tienda, todas sus secciones/páginas clave (inicio, catálogo/servicios, detalle, contacto, etc.) con navegación que funcione. '
            .'Usa contenido realista y MUY específico del negocio (nombres, textos, datos de ejemplo creíbles del giro). Debe sentirse como el producto TERMINADO, premium. '
            .'Varias páginas HTML enlazadas o una página con muchas secciones completas. No necesita backend real. Sigue el PROTOCOLO DE VERIFICACIÓN antes de terminar.');
    }

    /** Business/scope context shared by every build mode. */
    private function context(?Lead $lead, string $mode): array
    {
        $spec = $lead?->specs()->latest()->first();
        $features = collect($spec?->content['features'] ?? [])
            ->map(fn ($f) => is_array($f) ? trim(($f['name'] ?? '').(! empty($f['desc']) ? ' — '.$f['desc'] : '')) : $f)
            ->filter()->implode("\n- ");
        // Adjustments the client asked for during scope/demo — must be reflected in the build.
        $feedback = collect($spec?->content['feedback'] ?? [])->filter()->implode("\n- ");

        return [
            'business' => $lead?->company ?: ($lead?->name ?: 'el negocio'),
            'need' => $lead?->summary ?: 'sitio profesional a la medida',
            'features' => $features !== '' ? $features : 'sitio profesional a la medida',
            'feedback' => $feedback,
            'mode' => $mode,
        ];
    }

    private function run(int $id, string $dir, array $ctx, string $task): bool
    {
        File::ensureDirectoryExists($dir);
        @chmod($dir, 0777); // the non-root builder user must be able to write here
        $this->writeContext($dir, $ctx);

        try {
            // CRITICAL: scrub the panel's DB env from the agent's shell. The build runs inside the
            // panel container, which exports DB_HOST/DB_DATABASE/… for the panel's OWN production
            // Postgres. Without this, any `php artisan migrate`/`migrate:fresh` the agent runs would
            // connect to and WIPE the panel's database. Force a throwaway local sqlite instead.
            $scrub = 'unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_URL DB_SOCKET; '
                .'export DB_CONNECTION=sqlite DB_DATABASE='.escapeshellarg($dir.'/database/database.sqlite').'; '
                .'mkdir -p '.escapeshellarg($dir.'/database').' && : > '.escapeshellarg($dir.'/database/database.sqlite').' 2>/dev/null || true; ';
            // Claude Code refuses --dangerously-skip-permissions as root, so run it as the
            // non-root 'builder' user. It auto-reads the CLAUDE.md we just wrote for full context.
            $inner = 'cd '.escapeshellarg($dir).' && '.$scrub.'HOME=/home/builder claude -p '.escapeshellarg($task)
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
        } finally {
            @unlink($dir.'/CLAUDE.md'); // keep the deployed site clean
        }
    }

    /** Write the project's CLAUDE.md: full context + brand/image rules + verification protocol. */
    private function writeContext(string $dir, array $ctx): void
    {
        if (($ctx['mode'] ?? '') === 'fullstack') {
            $this->writeFullstackContext($dir, $ctx);

            return;
        }
        $isChange = $ctx['mode'] === 'change';
        $feedbackBlock = ! empty($ctx['feedback'])
            ? "\n## Ajustes que pidió el cliente (OBLIGATORIO reflejarlos)\n- {$ctx['feedback']}\n"
            : '';
        $verifyStep = $isChange
            ? '4. EL CAMBIO: ya exploraste y editaste — ahora CONFIRMA con curl/grep que el valor NUEVO aparece en el HTML/CSS servido (no el viejo). Si no aparece, corrígelo y vuelve a verificar.'
            : '4. Revisa que no haya enlaces rotos, secciones vacías, imágenes que no cargan ni errores en consola. El contenido debe ser realista y del giro.';

        $md = <<<MD
        # Overcloud — sitio para {$ctx['business']}

        Eres un **desarrollador senior de Overcloud** (agencia web). NO TERMINAS hasta verificar —sirviendo el sitio y revisándolo— que todo funciona y se ve profesional.

        ## Negocio
        - **Nombre:** {$ctx['business']}
        - **Necesidad:** {$ctx['need']}
        - **Funciones del alcance:**
        - {$ctx['features']}
        {$feedbackBlock}
        ## Reglas de marca (OBLIGATORIO)
        - Footer en TODAS las páginas: "Desarrollado por Overcloud" donde *Overcloud* enlaza a https://wa.me/5215594356241, y un "¿Quieres tu sitio? Escríbenos por WhatsApp" al mismo enlace.
        - NUNCA menciones Claude, IA ni herramientas internas en el contenido del sitio. Todo es de Overcloud.
        - Español, tono profesional y cálido.

        ## Imágenes (CRÍTICO)
        - NO uses picsum.photos ni fotos aleatorias o genéricas, y JAMÁS paisajes, ciudades, monumentos o lugares ajenos al giro (nada de puentes, rascacielos u otros países).
        - Prioriza gradientes CSS, colores de marca, patrones y emojis/íconos temáticos grandes (siempre cargan y siempre son del tema).
        - Si de verdad necesitas una foto real: https://source.unsplash.com/<ancho>x<alto>/?<palabras-MUY-específicas-del-giro> (p. ej. taquería: tacos,comida-mexicana).

        ## Técnico
        - SOLO HTML + CSS + JavaScript puro (sin backend, sin npm/composer). Autónomo, rápido, confiable.
        - Debe existir un Dockerfile que sirva los estáticos en el puerto 8080: `FROM python:3-alpine` / `WORKDIR /app` / `COPY . /app` / `EXPOSE 8080` / `CMD ["python","-m","http.server","8080"]`.
        - Diseño moderno, responsivo, pulido y atractivo.

        ## PROTOCOLO DE VERIFICACIÓN — NO termines sin completarlo
        1. Escribe/edita TODOS los archivos completos (sin TODOs ni medias tintas).
        2. Sirve el sitio para probarlo: `php -S 127.0.0.1:8099 >/tmp/srv.log 2>&1 &` dentro del directorio (o `python3 -m http.server 8099`). Usa otro puerto si está ocupado.
        3. Con curl confirma que CADA página responde y trae lo esperado: el nombre del negocio, las secciones clave y el footer de Overcloud. Ej: `curl -s 127.0.0.1:8099/ | grep -i "{$ctx['business']}"` y `curl -s 127.0.0.1:8099/ | grep -i overcloud`.
        {$verifyStep}
        5. Si algo falla, CORRÍGELO y vuelve a verificar. Repite hasta que TODO esté correcto.
        6. Al terminar, haz `kill` del servidor local. NO hagas git push ni despliegues.
        MD;

        File::put($dir.'/CLAUDE.md', $md);
        @chmod($dir.'/CLAUDE.md', 0666);
    }

    /** CLAUDE.md for a REAL Laravel + Vue (Inertia) app: modules = migrations/models/controllers/Vue,
     *  admin panel, login, Postgres-backed persistent data. */
    private function writeFullstackContext(string $dir, array $ctx): void
    {
        $feedbackBlock = ! empty($ctx['feedback']) ? "\n## Ajustes pedidos por el cliente (OBLIGATORIO)\n- {$ctx['feedback']}\n" : '';
        $email = $ctx['admin']['email'] ?? 'admin@overcloud.us';
        $pass = $ctx['admin']['password'] ?? 'Overcloud2026';

        $md = <<<MD
        # Overcloud — sistema Laravel + Vue para {$ctx['business']}

        Eres un **desarrollador senior full-stack de Overcloud**. Esta carpeta YA es una app **Laravel 13 + Inertia + Vue 3** funcionando, con **login/registro** y base de datos. Tu trabajo es **EXTENDERLA** (no rehacerla) para convertirla en el sistema real del cliente. NO TERMINAS hasta verificar —sirviendo la app, iniciando sesión y guardando datos reales— que cada módulo funciona.

        ## Negocio
        - **Nombre:** {$ctx['business']}
        - **Necesidad:** {$ctx['need']}
        - **Módulos del alcance (cada uno = funcionalidad REAL):**
        - {$ctx['features']}
        {$feedbackBlock}
        ## Qué construir (OBLIGATORIO)
        - Por CADA módulo: una **migración** (tablas reales), **modelo** Eloquent (con relaciones), **controlador** (CRUD), **rutas** protegidas con `auth`, y **páginas Inertia/Vue** en `resources/js/Pages/<Modulo>/` con tabla (listar, buscar, filtrar), formulario de crear/editar y borrar. Datos SIEMPRE en la base de datos (persistentes) — JAMÁS localStorage.
        - Un **PANEL DE ADMINISTRACIÓN**: un dashboard tras login (`/dashboard`) con tarjetas/resumen y un menú lateral que enlaza a todos los módulos. Reusa el layout autenticado existente.
        - Interconecta los módulos donde aplique (relaciones entre tablas; un registro se refleja en lo relacionado).
        - **Usuario admin** sembrado en `database/seeders/DatabaseSeeder.php`: email `{$email}`, contraseña `{$pass}` (usa `User::factory()` o `User::updateOrCreate`, con `Hash::make`). El seeder debe ser idempotente.

        ## Reglas de marca
        - Español, profesional. En el pie o el menú: "Desarrollado por Overcloud". NUNCA menciones Claude, IA ni herramientas internas.

        ## Técnico (IMPORTANTE para que despliegue)
        - En producción la base de datos es **PostgreSQL** (inyectada por variables de entorno DB_*). NO cambies el `config/database.php`; Laravel ya lo lee del entorno. En local puedes usar el sqlite que ya viene para probar.
        - Migraciones idempotentes y compatibles con Postgres (evita tipos solo-MySQL).
        - Asegúrate de que `docker/start.sh` ejecute las migraciones y el seed al arrancar: debe incluir `php artisan migrate --force` y `php artisan db:seed --force` ANTES de `php artisan serve`. Si no están, agrégalos.
        - Compila los assets: corre `npm install` (si hace falta) y **`npm run build`**, y deja el resultado committeado en `public/build`.

        ## PROTOCOLO DE VERIFICACIÓN — NO termines sin completarlo (déjalo PERFECTO)
        1. `composer install` (si falta) y `npm run build`. Corrige CUALQUIER error de compilación de Vue/Inertia hasta que `npm run build` pase limpio.
        2. Migra y siembra contra el sqlite local de prueba: `php artisan migrate:fresh --seed`. Debe correr SIN errores (migraciones compatibles con Postgres y SQLite).
        3. **Pruebas Pest (e2e de backend) OBLIGATORIAS**: escribe en `tests/Feature/` una prueba por módulo que: (a) inicie sesión como el admin sembrado, (b) cree un registro vía el endpoint/controlador real, (c) lo lea de vuelta desde la base de datos, (d) confirme que persiste. Corre `php artisan test` y NO termines hasta que TODAS pasen.
        4. **Prueba de NAVEGADOR (Playwright) OBLIGATORIA**: instálalo si falta (`composer require pestphp/pest-plugin-browser --dev --no-interaction` y `npx playwright install chromium`) y escribe en `tests/Browser/` una prueba que, en un navegador real contra la app servida localmente: abra `/login`, inicie sesión con el admin, navegue al dashboard y a cada módulo, **cree un registro por la interfaz**, recargue la página y **confirme que el dato sigue ahí** (persistencia real). Corre `php artisan test --filter=Browser` (o `./vendor/bin/pest tests/Browser`) y NO termines hasta que pase. Si el navegador no se puede instalar en este entorno, déjalo escrito y asegúrate de que las pruebas Pest del paso 3 cubran login + CRUD + persistencia de cada módulo.
        5. Confirma que el dashboard lista TODOS los módulos y que cada CRUD guarda en la BD (NUNCA en memoria/localStorage).
        6. Si algo falla, CORRÍGELO y repite los pasos hasta que TODO pase. Al terminar, `kill` de procesos locales. NO hagas git push ni deploy.
        MD;

        File::put($dir.'/CLAUDE.md', $md);
        @chmod($dir.'/CLAUDE.md', 0666);
    }
}
