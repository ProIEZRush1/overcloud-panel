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
     *  fully client-branded UI, admin panel, login, Postgres-backed persistent data, and a real
     *  headless-browser end-to-end self-test the agent must pass before finishing. */
    private function writeFullstackContext(string $dir, array $ctx): void
    {
        $feedbackBlock = ! empty($ctx['feedback']) ? "\n## Ajustes pedidos por el cliente (OBLIGATORIO)\n- {$ctx['feedback']}\n" : '';
        $email = $ctx['admin']['email'] ?? 'admin@overcloud.us';
        $pass = $ctx['admin']['password'] ?? 'Overcloud2026';
        $business = $ctx['business'];
        // The agent will write APP_NAME into env files via this exact, shell-safe value.
        $appName = $this->envSafe($business);

        $md = <<<MD
        # Overcloud — sistema Laravel + Vue **a la medida de {$business}**

        Eres un **desarrollador senior full-stack de Overcloud**. Esta carpeta YA es una app **Laravel 13 + Inertia + Vue 3 + Tailwind** funcionando, con **login/registro**, layout autenticado y base de datos. Tu trabajo es **EXTENDERLA y PERSONALIZARLA POR COMPLETO** (no rehacerla) hasta que se vea y se sienta como un sistema hecho exclusivamente para **{$business}**. NO TERMINAS hasta que una **prueba de navegador headless real contra la app corriendo localmente** pase en verde, sola, sin ayuda humana.

        ⚠️ **REGLA DE ORO:** cuando termines, NADA puede parecer un Laravel/Breeze genérico. Si en cualquier pantalla queda el logo de Laravel, el texto "You're logged in!", "Dashboard" pelón en inglés, Lorem ipsum, o el nombre "Laravel" en cualquier lado → el trabajo está MAL y debes corregirlo.

        ## Negocio
        - **Nombre del negocio (cliente):** {$business}
        - **Necesidad:** {$ctx['need']}
        - **Módulos del alcance (cada uno = funcionalidad REAL):**
        - {$ctx['features']}
        {$feedbackBlock}
        ## PASO 0 — EXPLORA antes de tocar (OBLIGATORIO)
        Antes de escribir nada, lee la plantilla actual para respetar SUS convenciones (imports, props, layout):
        - `resources/js/app.js` o `app.ts` (cómo se monta Inertia), `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/Layouts/GuestLayout.vue`
        - `resources/js/Pages/Auth/Login.vue` y `resources/js/Pages/Dashboard.vue`
        - `resources/js/Components/ApplicationLogo.vue` (el logo "L" de Laravel — lo vas a REEMPLAZAR)
        - `app/Http/Middleware/HandleInertiaRequests.php` (ya comparte `'name' => config('app.name')`, disponible en Vue como `\$page.props.name`)
        - `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `package.json`, `vite.config.*`
        Tus archivos nuevos DEBEN compilar con ese mismo setup de Vite/Inertia/Breeze. No introduzcas librerías nuevas si la plantilla ya resuelve lo mismo.

        ## 1) PERSONALIZAR LA MARCA AL CLIENTE (OBLIGATORIO — esto es lo primero que verá el cliente)
        - **APP_NAME = el nombre del negocio.** Escribe `APP_NAME="{$appName}"` en `.env` Y en `.env.production` (reemplaza la línea existente; no la dupliques), y cambia también el `'name' => env('APP_NAME', '…')` por defecto en `config/app.php` a `'{$appName}'` como respaldo. Así `config('app.name')` y `\$page.props.name` muestran "{$business}" en toda la app.
        - **Usa el nombre del negocio en la UI vía `\$page.props.name`** (no lo hardcodees en cada vista): título del navbar, encabezado del dashboard, `<title>` de las páginas, y el mensaje de bienvenida del login.
        - **ELIMINA el logo de Laravel.** Sobrescribe `resources/js/Components/ApplicationLogo.vue` por un **wordmark limpio**: el nombre del negocio (`\$page.props.name`) junto a una marca geométrica simple (un cuadrado/rombo redondeado o iniciales) pintada con el **degradado de marca**. PROHIBIDO el SVG del cubo "L" de Laravel en cualquier parte.
        - **Tema visual (aplícalo en login, dashboard y layout autenticado):** moderno, profesional, dark-friendly. Degradado primario **#7c3aed → #c026d3** (morado a fucsia) para encabezados, botones primarios y el wordmark. Tarjetas `rounded-2xl`, sombras suaves (`shadow-lg`/`shadow-xl`), espaciado generoso, tipografía clara. Usa Tailwind (ya disponible). Botón primario sugerido: `bg-gradient-to-r from-[#7c3aed] to-[#c026d3] text-white rounded-xl px-4 py-2 shadow-lg hover:opacity-90`.
        - **Login (`Pages/Auth/Login.vue`):** cabecera con el wordmark + un texto cálido en español tipo "Bienvenido a {$business}. Inicia sesión para administrar tu sistema." Labels y botones en español ("Correo", "Contraseña", "Recuérdame", "Iniciar sesión").
        - **Dashboard (`Pages/Dashboard.vue`):** REEMPLAZA por completo el "You're logged in!" por un panel real en español: saludo con el nombre del negocio, y **una tarjeta-resumen por módulo** (ícono/emoji del giro + conteo real de registros traído del controlador + enlace "Ver / Administrar"). Nada de texto placeholder.
        - **Navegación:** en `AuthenticatedLayout.vue` el menú (top o lateral) debe listar **TODOS los módulos del alcance** con etiquetas en español del giro del negocio (no "Items" genérico). Marca el activo.
        - **TODO el texto visible en español**, tono cálido y profesional, redactado para el giro de {$business}. Crédito a Overcloud SOLO como nota pequeña en el pie ("Desarrollado por Overcloud"). NUNCA menciones Claude, IA ni herramientas internas.

        ## 2) CONSTRUIR CADA MÓDULO COMO FUNCIONALIDAD REAL (OBLIGATORIO)
        Por CADA módulo del alcance, todo conectado y persistente:
        - **Migración** con columnas reales y apropiadas al giro (no una tabla genérica).
        - **Modelo** Eloquent con `\$fillable` y relaciones donde aplique.
        - **Controlador** con CRUD completo (`index`, `create`, `store`, `edit`, `update`, `destroy`) devolviendo respuestas Inertia.
        - **Rutas** en `routes/web.php` protegidas con middleware `auth` (resource routes).
        - **Páginas Inertia/Vue** en `resources/js/Pages/<Modulo>/` (`Index.vue` con tabla + búsqueda/filtro + botones, `Create.vue`/`Edit.vue` con formulario validado usando `useForm`). Estilo de marca.
        - **Enlazado en la navegación** y reflejado en el dashboard.
        - **Datos SIEMPRE en la base de datos** (persistentes). JAMÁS localStorage ni estado en memoria.
        - Interconecta módulos donde tenga sentido (relaciones; un registro aparece en lo relacionado).
        - **Usuario admin** idempotente en `database/seeders/DatabaseSeeder.php`: email `{$email}`, contraseña `{$pass}` (usa `User::updateOrCreate([...], [...'password' => Hash::make('{$pass}')])`). Siembra también algunos registros de ejemplo realistas del giro por módulo, para que el dashboard no se vea vacío.

        ## 3) BASE DE DATOS — SOLO SQLITE LOCAL (CRÍTICO)
        - Este entorno ya está forzado a un **sqlite local** (`database/database.sqlite`) y se borraron las variables DB_* del panel. **JAMÁS** corras un comando de base de datos contra otra cosa que no sea ese sqlite local. NO te conectes a Postgres ni a ninguna BD remota, NO exportes DB_HOST/DB_*, NO uses `migrate:fresh` apuntando a otro lado.
        - Migraciones **compatibles con Postgres Y SQLite** (evita tipos/funciones solo-MySQL; usa `json`, `decimal`, `string`, `text`, `timestamps`, etc.). En producción el harness inyecta Postgres y corre las migraciones al arrancar; tú solo validas en sqlite local. NO toques `config/database.php` salvo lo permitido arriba.

        ## 4) COMPILAR ASSETS (OBLIGATORIO — la plantilla YA committea `public/build`)
        - Corre `npm install` (si hace falta) y **`npm run build`** hasta que pase limpio. `public/build` debe quedar fresco y será committeado (ya NO está en `.gitignore`). Si modificaste cualquier `.vue`/`.js`/`.ts`, vuelve a correr `npm run build` antes de terminar para que el bundle desplegado refleje tus cambios.

        ## PROTOCOLO DE AUTO-PRUEBA E2E — el bot se prueba a sí mismo (NO termines sin verde)
        Debes levantar la app de verdad y manejar un **navegador headless real** contra ella. El entorno ya instala **Playwright + Chromium**. Hazlo así:

        1. **Compila y prepara la BD local:** `composer install` (si falta) → `npm run build` (limpio) → `php artisan key:generate --force` → `php artisan migrate:fresh --seed` contra el **sqlite local** (debe correr sin errores).
        2. **(Recomendado, bucle interno rápido) Pruebas Pest backend:** en `tests/Feature/`, una por módulo: inicia sesión como el admin, crea un registro por el controlador real, recárgalo desde la BD, confirma que persiste. `php artisan test` debe pasar.
        3. **Levanta la app servida localmente:**
           `php artisan serve --host 127.0.0.1 --port 8123 >/tmp/app.log 2>&1 &`
           Espera a que responda (`curl -fs http://127.0.0.1:8123/login` con reintentos). Usa otro puerto si 8123 está ocupado.
        4. **PRUEBA DE NAVEGADOR HEADLESS OBLIGATORIA (la verdadera puerta de salida):** asegúrate de tener el paquete npm `playwright` y el binario chromium (`npm i -D playwright >/dev/null 2>&1 || true`; `npx playwright install chromium`). Escribe un script autocontenido **`tests/e2e/smoke.mjs`** con `import { chromium } from 'playwright'` que, en chromium **headless** contra `http://127.0.0.1:8123`:
           - abra `/login`, llene correo `{$email}` y contraseña `{$pass}`, envíe, y confirme que llega al dashboard (URL `/dashboard` y el nombre "{$business}" visible);
           - por **CADA módulo**: navegue a su listado, use el botón de crear, **llene el formulario y guarde un registro por la INTERFAZ**, confirme que el nuevo registro aparece en la tabla;
           - **recargue la página** (o navegue fuera y vuelva) y confirme que el registro **sigue ahí** (persistencia real en BD);
           - ante cualquier fallo, lance error con contexto (`process.exit(1)`).
           Córrelo con `node tests/e2e/smoke.mjs`. Debe salir con código 0.
        5. **AUTO-REPARACIÓN hasta verde:** si el script falla, lee `/tmp/app.log` y `storage/logs/laravel.log`, diagnostica (ruta, validación, prop de Inertia, selector, migración, assets sin recompilar…), **corrige el código**, vuelve a `npm run build`, reinicia el server y **re-ejecuta `node tests/e2e/smoke.mjs`**. Repite hasta que pase en verde. Esta prueba debe pasar SIN intervención humana — es el bot probándose a sí mismo.
        6. **Anti-genérico (revisión final manual con curl + el navegador):** confirma que NO aparece el logo de Laravel ni "You're logged in" ni "Laravel" en ninguna pantalla, que el nombre "{$business}" se muestra en login y dashboard, y que cada módulo está en el menú. `curl -s http://127.0.0.1:8123/login` y el HTML del dashboard NO deben contener "Laravel".
        7. **Cierre:** confirma que `docker/start.sh`, las migraciones, el seed idempotente, `public/build` fresco y todos los archivos quedaron completos (sin TODOs). Haz `kill` de los procesos locales (server). NO hagas git push ni deploy — de eso se encarga el harness.
        MD;

        File::put($dir.'/CLAUDE.md', $md);
        @chmod($dir.'/CLAUDE.md', 0666);
    }

    /** Sanitize a business name for safe inclusion in an env value / config string (drops quotes/newlines). */
    private function envSafe(string $value): string
    {
        $clean = preg_replace('/[\r\n"\\\\]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean) ?: 'Sistema';
    }
}
