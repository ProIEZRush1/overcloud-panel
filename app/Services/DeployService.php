<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\ProjectStatus;
use App\Models\Lead;
use App\Models\Project;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Autonomously builds, deploys and self-heals a client's site/app:
 *  1. Pick the stack (Laravel+Vue for sites, Flutter Web for apps, Next.js/static).
 *  2. AI generates content (content.json) from the confirmed scope.
 *  3. Generate a repo from the stack's GitHub template + inject content.
 *  4. Create a Coolify app, then loop: deploy -> wait build -> E2E verify the live
 *     URL actually works; retry on failure up to max_attempts. Only "live" when verified.
 */
class DeployService
{
    private const OWNER_WA = '5215594356241';

    public function __construct(private Assistant $assistant, private AgentBuildService $agent, private WhatsAppGateway $gateway) {}

    public function isConfigured(): bool
    {
        $c = config('overcloud.deploy');

        return filled($c['github_token']) && filled($c['coolify_token']);
    }

    public function deploy(Project $project): ?string
    {
        if (! $this->isConfigured()) {
            Log::warning('Autodeploy not configured');

            return null;
        }
        $c = config('overcloud.deploy');
        $project->loadMissing('lead.service');

        $stackKey = $this->pickStack($project);
        $stack = $c['stacks'][$stackKey] ?? $c['stacks'][$c['default_stack']];
        $name = Str::slug(($project->lead?->company ?: $project->lead?->name ?: 'sitio')).'-'.Str::lower(Str::random(5));
        $label = $this->projectLabel($project);

        // Announce the build START to the client only ONCE per project — a retry or re-dispatch must
        // never re-spam "¡Manos a la obra!" + the progress link (which looks broken to the client).
        $announce = empty($project->brief['announced']);
        if ($announce) {
            $this->notify($project, "¡Manos a la obra! 🛠️ Empecé a construir {$label}. Te voy contando el avance por aquí. 🙌");
            $project->update(['brief' => array_merge((array) ($project->brief ?? []), ['announced' => true])]);
        }

        $content = $this->generateContent($project);

        // Apps / SaaS → a REAL Laravel + Vue app (login, admin panel, Postgres-backed permanent data).
        // Static/marketing sites keep the fast agentic/template path.
        $fullstack = $this->usesFullstack($project);
        $bot = $this->usesBot($project); // a WhatsApp-bot product (clones the bot template + Baileys gateway)
        $custom = ! $fullstack && $this->usesCustomBuild($project, $stack);
        $dir = ($fullstack || $custom) ? storage_path('builds/'.$name) : null;
        $admin = $fullstack ? $this->ensureAdminCreds($project) : [];

        if ($fullstack) {
            // Verify the Laravel+Vue app actually rendered (Inertia mount present) — not the Flutter
            // markers pickStack would otherwise use, and not the business name (client-rendered).
            $stackKey = $bot ? 'whatsapp-bot' : 'laravel-vue';
            $stack = ['kind' => 'app', 'markers' => ['id="app"'], 'port' => '8080'];
        }

        if ($fullstack) {
            $this->reportProgress($project, 0, $announce
                ? ($bot
                    ? "Estoy construyendo tu *bot de WhatsApp* — con su panel de administración para que conectes tu WhatsApp y empiece a vender solo. 🤖\n\n📺 Avance en vivo:\n".$this->progressUrl($project)
                    : "Estoy desarrollando {$label} como un *sistema completo* — con inicio de sesión, panel de administración y base de datos. 🛠️\n\n📺 Puedes ver el avance en vivo aquí:\n".$this->progressUrl($project))
                : null);
            $templateRepo = $c['stacks'][$bot ? 'whatsapp-bot' : 'laravel-vue']['repo'];
            if (! $this->agent->isAvailable() || ! $this->cloneRepo($c, $templateRepo, $dir)) {
                return $this->fail($project, 'no se pudo construir el sistema');
            }
            $this->reportProgress($project, 1, $announce ? '⚙️ Estoy programando tus módulos, tu panel de administración y conectando todo. Esto toma varios minutos.' : null);
            $builtOk = $bot ? $this->agent->buildBot($project, $dir, $admin) : $this->agent->buildFullstack($project, $dir, $admin);
            if (! $builtOk) {
                return $this->fail($project, 'no se pudo construir el sistema');
            }
            $this->ensureMigrateOnStart($dir); // run migrations + seed on every boot (permanent Postgres)
            if (! $this->createRepo($c, $name) || ! $this->pushDir($c, $dir, $name)) {
                return $this->fail($project, 'no se pudo crear el repositorio del sistema');
            }
        } elseif ($custom) {
            if ($announce) {
                $this->notify($project, "Estoy desarrollando {$label} a la medida — diseño, funciones y panel de administración. Esto toma unos minutos. ⚙️");
            }
            if (! $this->agent->isAvailable()
                || ! $this->agent->build($project, $stackKey, $content, $dir)
                || ! $this->createRepo($c, $name)
                || ! $this->pushDir($c, $dir, $name)) {
                return $this->fail($project, 'no se pudo construir el proyecto a la medida');
            }
        } else {
            if ($announce) {
                $this->notify($project, "Estoy armando {$label} con tu diseño y contenido. 🎨");
            }
            if (! $this->generateRepo($c, $stack['repo'], $name)) {
                return $this->fail($project, 'no se pudo crear el repositorio');
            }
            $this->updateContent($c, $name, $content);
        }

        // Reuse the project's existing Coolify app on a retry instead of creating a duplicate.
        if ($project->coolify_app_uuid) {
            $uuid = $project->coolify_app_uuid;
            $url = $project->prod_url ?: '';
        } else {
            $slug = Str::slug($project->lead?->company ?: $project->lead?->name ?: 'sitio');
            $this->removeStaleApps($c, $slug.'-', $name);
            // Custom static builds and the Laravel+Vue full-stack app both serve on 8080; only the
            // light template build uses the stack's own port. Mismatch here = 502.
            $port = ($fullstack || $custom) ? '8080' : $stack['port'];
            [$uuid, $url] = $this->createApp($c, $name, $port);
        }
        if (! $uuid) {
            return $this->fail($project, 'no se pudo crear la app en Coolify');
        }

        $brief = (array) ($project->brief ?? []);
        $brief['stack'] = $stackKey;
        $project->update([
            'status' => ProjectStatus::Building,
            'coolify_app_uuid' => $uuid,
            'repo_url' => "https://github.com/{$c['github_owner']}/{$name}",
            'prod_url' => $url,
            'brief' => $brief,
        ]);

        // Nice domain under overcloud.us (falls back to the default sslip.io if Cloudflare unset).
        // The subdomain is unique PER PROJECT (uuid suffix) so two clients with the same name
        // never share a URL — and stable across redeploys so a client's link never changes.
        $nice = $this->assignDomain($c, $uuid, $this->subdomainFor($project));
        if ($nice) {
            $url = $nice;
            $project->update(['prod_url' => $url, 'domain' => $url]);
        }

        // Inject env: client-shared credentials (Stripe, API keys…) + — for full-stack apps — a
        // dedicated Postgres (permanent data) and the runtime config (APP_URL = final domain).
        $env = (array) ($brief['env'] ?? []);
        if ($fullstack) {
            $this->reportProgress($project, 2, '🗄️ Creando tu base de datos segura donde se guardará toda tu información de forma permanente.');
            $db = (array) ($brief['db'] ?? []);
            if (empty($db['DB_HOST'])) {
                $db = $this->provisionDatabase($c, $name);
                if (! empty($db['DB_HOST'])) {
                    $brief['db'] = $db;
                    $project->update(['brief' => $brief]);
                }
            }
            $business = $project->lead?->company ?: ($project->lead?->name ?: 'Mi Negocio');
            $env = array_merge($env, $db, [
                'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => $url,
                // Branded UI: the template's login/dashboard show the business name (config('app.name'))
                // and https asset URLs behind the proxy.
                'APP_NAME' => $business, 'ASSET_URL' => $url,
            ]);
            if ($bot) {
                // Shared secret between the Laravel app and the embedded Baileys gateway (same container).
                $env['GATEWAY_TOKEN'] = (string) ($brief['db']['GATEWAY_TOKEN'] ?? Str::password(24, true, true, false));
                $brief['bot'] = true;
                $brief['connect_url'] = rtrim($url, '/').'/conectar';
                $project->update(['brief' => $brief]);
            }
            unset($env['DB_UUID']); // metadata for cleanup, not an app env var
            // Record the admin login URL so the delivery message hands over real access.
            $brief['admin'] = array_merge((array) ($brief['admin'] ?? []), ['url' => rtrim($url, '/').'/login']);
            $project->update(['brief' => $brief]);
        }
        $this->applyEnv($c, $uuid, $env);

        if ($fullstack) {
            $this->reportProgress($project, 3, "¡Ya casi! 🚀 Estoy publicando {$label} en línea.");
        } else {
            $this->notify($project, "¡Ya casi! 🚀 Estoy publicando {$label} en línea y revisando que todo funcione bien...");
        }

        // Self-heal: deploy -> wait for the build -> E2E verify the LIVE URL (the source
        // of truth, with retries for routing delay); retry the whole cycle on failure.
        for ($attempt = 1; $attempt <= (int) $c['max_attempts']; $attempt++) {
            if ($fullstack) {
                $this->reportProgress($project, 4);
            }
            $depUuid = $this->triggerDeploy($c, $uuid);

            $verdict = $this->waitForLive($c, $depUuid, $url, $content, $stack);
            // For a full-stack app, "responding" is not enough — the BOT must confirm end-to-end on the
            // LIVE url that the SPA renders, assets load over https, and the DB is reachable (login works,
            // migrations ran, admin seeded). Only then is it truly delivered; otherwise repair + retry.
            if ($verdict['ok'] && $fullstack) {
                $live = $this->verifyLiveApp($url);
                if (! $live['ok']) {
                    $verdict = ['ok' => false, 'reason' => 'e2e en vivo: '.$live['reason']];
                } elseif ($dir && ! $this->agent->verifyAndHeal($project, $url, $admin, $uuid, $dir, $bot)) {
                    // Deep autonomous QA: the agent logs in for real, opens the connect/QR page and clicks
                    // every module, self-healing anything broken. If it still can't pass, this attempt fails.
                    $verdict = ['ok' => false, 'reason' => 'verificación E2E (login/conectar/módulos) no pasó'];
                }
            }
            if ($verdict['ok']) {
                $project->update(['status' => ProjectStatus::Live, 'delivered_at' => now()]);
                if ($fullstack) {
                    $this->reportProgress($project, 5, null, true);
                }
                Log::info('Deploy live', ['project' => $project->id, 'url' => $url, 'attempt' => $attempt]);

                return $url;
            }

            Log::warning('Deploy attempt failed', ['project' => $project->id, 'attempt' => $attempt, 'reason' => $verdict['reason']]);
            // Tell the client we're polishing it — but ONLY ONCE ever (across all attempts AND job
            // retries), never on every retry, or it spams "afinando unos detalles" repeatedly.
            if ($attempt < (int) $c['max_attempts'] && empty($project->fresh()->brief['polishing_sent'])) {
                $this->notify($project, "Estoy afinando unos detalles para que {$label} quede perfecto. 🔧");
                $project->update(['brief' => array_merge((array) ($project->fresh()->brief ?? []), ['polishing_sent' => true])]);
            }
            // Let Claude Code repair the repo from the deploy logs before retrying (self-heal).
            $logs = $this->fetchLogs($c, $depUuid);
            if ($fullstack && $dir && $this->agent->repairFullstack($project, $dir, $logs, $admin)) {
                $this->ensureMigrateOnStart($dir);
                $this->pushDir($c, $dir, $name);
            } elseif ($custom && $dir && $this->agent->repair($project, $stackKey, $dir, $logs)) {
                $this->pushDir($c, $dir, $name);
            }
            sleep(4);
        }

        return $this->fail($project, 'no pasó las pruebas tras varios intentos');
    }

    /**
     * Set a single runtime env var on the panel's OWN Coolify app (dedup first so Coolify's bulk append
     * never piles up duplicates). Used by the keepalive to keep CLAUDE_CREDS_JSON current so a redeploy
     * always re-seeds the build agent from a VALID (just-rotated) token instead of a stale snapshot.
     */
    public function updatePanelEnv(string $key, string $value): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $c = config('overcloud.deploy');
        $panel = (string) $c['panel_app_uuid'];
        try {
            $existing = Http::withToken($c['coolify_token'])->timeout(20)->get($c['coolify_url']."/applications/{$panel}/envs")->json();
            foreach ((is_array($existing) ? $existing : []) as $e) {
                if (($e['key'] ?? '') === $key && ! empty($e['uuid'])) {
                    Http::withToken($c['coolify_token'])->timeout(15)->delete($c['coolify_url']."/applications/{$panel}/envs/{$e['uuid']}");
                }
            }
            $r = Http::withToken($c['coolify_token'])->timeout(30)->patch($c['coolify_url']."/applications/{$panel}/envs/bulk",
                ['data' => [['key' => $key, 'value' => $value, 'is_build_time' => false, 'is_preview' => false]]]);

            return $r->successful();
        } catch (\Throwable $e) {
            Log::warning('updatePanelEnv failed', ['key' => $key, 'e' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Tear down an EXPIRED 5-day trial's infrastructure: the Coolify app, its dedicated Postgres and the
     * DNS record. Keeps the Project row in the panel (status flips to Cancelled) so the lead/history stays.
     */
    public function expireTrialInfra(Project $project): void
    {
        if (! $this->isConfigured()) {
            return;
        }
        $c = config('overcloud.deploy');
        try {
            if ($project->coolify_app_uuid) {
                Http::withToken($c['coolify_token'])->timeout(30)
                    ->delete($c['coolify_url'].'/applications/'.$project->coolify_app_uuid, ['delete_volumes' => true, 'delete_configurations' => true]);
            }
            $dbUuid = (string) ($project->brief['db']['DB_UUID'] ?? '');
            if ($dbUuid !== '') {
                Http::withToken($c['coolify_token'])->timeout(30)
                    ->delete($c['coolify_url'].'/databases/'.$dbUuid, ['delete_volumes' => true, 'delete_configurations' => true]);
            }
            $this->deleteDns($c, $this->subdomainFor($project));
            Log::info('expired trial infra torn down', ['project' => $project->id]);
        } catch (\Throwable $e) {
            Log::warning('expireTrialInfra failed', ['project' => $project->id, 'e' => $e->getMessage()]);
        }
    }

    /** Free a lost lead's demo: delete its Coolify app(s) and DNS record. Keeps the DB record intact. */
    public function removeDemo(Lead $lead): void
    {
        if (! $this->isConfigured()) {
            return;
        }
        $c = config('overcloud.deploy');
        $slug = Str::slug($lead->company ?: $lead->name ?: 'sitio');
        try {
            // Delete every Coolify app for this lead's demo (name starts with "<slug>-demo-").
            $apps = Http::withToken($c['coolify_token'])->timeout(20)->get($c['coolify_url'].'/applications')->json();
            foreach ((is_array($apps) ? $apps : []) as $a) {
                if (! empty($a['uuid']) && Str::startsWith($a['name'] ?? '', $slug.'-demo-')) {
                    Http::withToken($c['coolify_token'])->timeout(25)
                        ->delete($c['coolify_url']."/applications/{$a['uuid']}", ['delete_volumes' => true, 'delete_configurations' => true]);
                    Log::info('removed lost demo app', ['lead' => $lead->id, 'name' => $a['name']]);
                }
            }
            // Remove the demo DNS record so it doesn't dangle.
            $this->deleteDns($c, $this->demoSubdomain($lead));
        } catch (\Throwable $e) {
            Log::warning('removeDemo failed', ['lead' => $lead->id, 'e' => $e->getMessage()]);
        }
    }

    /** Build + deploy a quick one-page visual demo for a lead (before the quote). Returns the live URL. */
    public function deployDemo(Lead $lead): ?string
    {
        if (! $this->isConfigured() || ! $this->agent->isAvailable()) {
            return null;
        }
        $c = config('overcloud.deploy');
        $slug = Str::slug($lead->company ?: $lead->name ?: 'sitio');
        $name = $slug.'-demo-'.Str::lower(Str::random(4));
        $dir = storage_path('builds/'.$name);

        $this->reportDemoProgress($lead, 1); // designing the demo with their brand
        if (! $this->agent->buildDemo($lead, $dir)
            || ! $this->createRepo($c, $name)
            || ! $this->pushDir($c, $dir, $name)) {
            return null;
        }
        $this->reportDemoProgress($lead, 2); // publishing it online

        // Remove any previous demo app(s) for this client so the demo domain never collides
        // (re-running a demo used to leave stale apps fighting over <slug>-demo.overcloud.us).
        $this->removeStaleApps($c, $slug.'-demo-', $name);

        [$uuid, $url] = $this->createApp($c, $name, '8080');
        if (! $uuid) {
            return null;
        }

        // Nice demo domain under overcloud.us — unique per lead so two same-named clients
        // never share one demo URL (which would serve one client's demo to the other).
        $nice = $this->assignDomain($c, $uuid, $this->demoSubdomain($lead));
        if ($nice) {
            $url = $nice;
        }

        $stack = ['kind' => 'web', 'markers' => []];
        $content = ['business' => (string) ($lead->company ?? '')];
        for ($i = 1; $i <= 2; $i++) {
            $dep = $this->triggerDeploy($c, $uuid);
            if ($this->waitForLive($c, $dep, $url, $content, $stack)['ok']) {
                return $url;
            }
        }

        // Never confirmed live → don't hand the client a dead link. BuildDemo owns retry + the
        // owner alert (after all attempts), so just log here to avoid alerting on every attempt.
        Log::warning('demo not live after attempts', ['lead' => $lead->id, 'url' => $url]);

        return null;
    }

    /** Apps -> Flutter Web; sites -> default (Laravel+Vue). Explicit brief.stack wins. */
    public function pickStack(Project $project): string
    {
        $c = config('overcloud.deploy');
        $brief = (array) ($project->brief ?? []);
        if (! empty($brief['stack']) && isset($c['stacks'][$brief['stack']])) {
            return $brief['stack'];
        }
        $key = $project->lead?->service?->key;
        if (in_array($key, ['mobileapp', 'app', 'webapp'], true)) {
            return 'flutter';
        }

        return $c['default_stack'];
    }

    /** E2E: the live page must respond 200 and contain the stack's required markers. */
    public function verify(string $url, array $content, array $stack): array
    {
        try {
            $r = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'sin respuesta'];
        }
        if (! $r->successful()) {
            return ['ok' => false, 'reason' => 'HTTP '.$r->status()];
        }
        $html = $r->body();
        foreach (($stack['markers'] ?? []) as $m) {
            if (! Str::contains($html, $m)) {
                return ['ok' => false, 'reason' => "falta '{$m}'"];
            }
        }
        // Web stacks render content into the served HTML (Inertia props / SSR) — confirm
        // the real business content is present, not a generic error/placeholder page.
        // NOTE: the always-present "Overcloud" footer is NOT proof the page built — checking it
        // made verify pass on a blank/error page, so we require the business's own content.
        // Match accent/emoji-insensitively (the rendered name may be restyled, e.g. "Café"/"Cafe ✡️")
        // and fall back to a distinctive word, so a CORRECTLY-applied change is never failed (→ rolled
        // back) just because the displayed name differs from the raw company string.
        if (($stack['kind'] ?? 'web') === 'web') {
            $biz = trim((string) ($content['business'] ?? ''));
            if ($biz !== '' && ! $this->htmlMentionsBusiness($html, $biz)) {
                return ['ok' => false, 'reason' => 'contenido no presente'];
            }
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    /** True if the served HTML shows the business — accent/emoji-insensitive, with a word fallback. */
    private function htmlMentionsBusiness(string $html, string $biz): bool
    {
        $htmlNorm = Str::lower(Str::ascii($html));
        $bizNorm = Str::lower(Str::ascii($biz));
        if ($bizNorm === '' || Str::contains($htmlNorm, $bizNorm)) {
            return true;
        }
        // Fallback: a distinctive word of the name renders (handles a restyled/abbreviated heading).
        foreach (preg_split('/\s+/', $bizNorm) as $word) {
            if (strlen($word) >= 4 && Str::contains($htmlNorm, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wait for the deploy: succeed the instant the live URL passes E2E (the truth),
     * fail fast if the build errors. Polls the URL first so "live" registers within
     * seconds of the site actually working, not after a slow build-status poll.
     */
    private function waitForLive(array $c, ?string $depUuid, string $url, array $content, array $stack): array
    {
        $last = ['ok' => false, 'reason' => 'sin respuesta'];
        // ~8 min: a proxied Cloudflare subdomain's first Let's Encrypt cert can take several
        // minutes to serve 200, so be patient before giving up (the client never sees this).
        for ($i = 0; $i < 60; $i++) {
            $last = $this->verify($url, $content, $stack);
            if ($last['ok']) {
                return $last;
            }
            // Only fail-fast on a real build failure, and only after giving the cert time to settle.
            if ($depUuid && $i >= 8) {
                $status = Http::withToken($c['coolify_token'])->timeout(15)
                    ->get($c['coolify_url']."/deployments/{$depUuid}")->json('status');
                if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
                    return ['ok' => false, 'reason' => 'build falló ('.$last['reason'].')'];
                }
            }
            sleep(8);
        }

        return $last;
    }

    private function generateContent(Project $project): array
    {
        $default = $this->defaultContent($project);
        if (! $this->assistant->isEnabled()) {
            return $default;
        }
        $lead = $project->lead;
        $spec = $lead?->specs()->latest()->first();
        try {
            $system = 'Eres un diseñador de contenido web. Genera el contenido para un sitio. Responde ÚNICAMENTE con JSON válido (sin ```), con esta forma: '
                .'{"business","tagline","theme":{"primary","accent"},"hero":{"eyebrow","title","subtitle","cta"},'
                .'"stats":[{"value","label"}],"strip":["..."],"products_title","products":[{"name","price","tag","seed"}],'
                .'"about":{"title","text","points":["..."]},"contact":{"title","text"}}. '
                .'Español, profesional, persuasivo. price como "$XXX MXN". Inventa 6-8 productos/servicios coherentes. theme.primary y accent en hex.';
            $ctx = 'Negocio: '.($lead?->company ?: $lead?->name ?: 'Negocio')
                .'. Tipo de proyecto: '.($lead?->service?->name ?? 'Sitio web')
                .'. Descripción: '.($lead?->summary ?? '').'.';
            if ($spec) {
                $ctx .= ' Secciones: '.collect($spec->content['pages'] ?? [])->map(fn ($p) => is_array($p) ? ($p['name'] ?? '') : $p)->implode(', ').'.';
            }
            $raw = $this->assistant->complete($system."\n\n".$ctx);
            $arr = json_decode($this->extractJson($raw) ?? '', true);
            if (is_array($arr) && ! empty($arr['business'])) {
                $arr = array_merge($default, $arr);
                $arr['built_by_whatsapp'] = self::OWNER_WA;
                foreach ($arr['products'] ?? [] as $i => &$p) {
                    $p['seed'] = $p['seed'] ?? ('p'.$i.Str::random(3));
                }

                return $arr;
            }
        } catch (\Throwable $e) {
            Log::warning('Content generation failed', ['e' => $e->getMessage()]);
        }

        return $default;
    }

    private function defaultContent(Project $project): array
    {
        $lead = $project->lead;
        $business = $lead?->company ?: ($lead?->name ?: 'Tu Negocio');
        $service = $lead?->service?->name ?? 'Sitio Web';
        $slug = Str::slug($business) ?: 'site';

        return [
            'business' => $business,
            'tagline' => $service,
            'theme' => ['primary' => '#7c3aed', 'accent' => '#db2777'],
            'built_by_whatsapp' => self::OWNER_WA,
            'nav' => ['Inicio', 'Servicios', 'Nosotros', 'Contacto'],
            'hero' => [
                'eyebrow' => 'Bienvenido',
                'title' => $business,
                'subtitle' => $lead?->summary ?: 'Tu nuevo sitio profesional, creado a la medida por Overcloud.',
                'cta' => 'Conócenos',
                'image' => "https://picsum.photos/seed/{$slug}h/700/820",
            ],
            'stats' => [['value' => '100%', 'label' => 'a tu medida'], ['value' => '24/7', 'label' => 'en línea'], ['value' => '⚡', 'label' => 'rápido']],
            'strip' => ['🚀 Sitio profesional', '📱 Adaptable a celular', '🔒 Seguro', '💬 Soporte por WhatsApp'],
            'products_title' => 'Lo que ofrecemos',
            'products' => collect(range(1, 6))->map(fn ($i) => ['name' => 'Servicio '.$i, 'price' => 'Cotizar', 'tag' => $i <= 2 ? 'Top' : '', 'seed' => $slug.$i])->all(),
            'about' => [
                'title' => 'Sobre '.$business,
                'text' => $lead?->summary ?: 'Somos un negocio comprometido con la calidad y el servicio.',
                'points' => ['Atención personalizada', 'Calidad garantizada', 'Compromiso con cada cliente'],
                'image' => "https://picsum.photos/seed/{$slug}a/800/700",
            ],
            'contact' => ['title' => 'Contáctanos', 'text' => 'Estamos para ayudarte. Escríbenos y con gusto te atendemos.'],
        ];
    }

    private function generateRepo(array $c, string $templateRepo, string $name): bool
    {
        $r = Http::withToken($c['github_token'])->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$c['github_owner']}/{$templateRepo}/generate", [
                'owner' => $c['github_owner'], 'name' => $name, 'private' => false,
            ]);
        if (! $r->successful()) {
            Log::warning('Repo generate failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);

            return false;
        }
        sleep(2);

        return true;
    }

    /** Inject the AI content into whichever content.json path the stack uses. */
    private function updateContent(array $c, string $repo, array $content): void
    {
        foreach (['resources/content.json', 'content.json', 'public/content.json', 'web/content.json', 'assets/content.json'] as $path) {
            $url = "https://api.github.com/repos/{$c['github_owner']}/{$repo}/contents/{$path}";
            $get = Http::withToken($c['github_token'])->get($url);
            if (! $get->successful()) {
                continue;
            }
            $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            Http::withToken($c['github_token'])->put($url, [
                'message' => 'Site content', 'content' => base64_encode($json), 'sha' => $get->json('sha'),
            ]);

            return;
        }
    }

    private function createApp(array $c, string $name, string $port): array
    {
        $r = Http::withToken($c['coolify_token'])->timeout(60)->post($c['coolify_url'].'/applications/public', [
            'project_uuid' => $c['coolify_project'], 'server_uuid' => $c['coolify_server'], 'environment_name' => 'production',
            'git_repository' => "https://github.com/{$c['github_owner']}/{$name}", 'git_branch' => 'main',
            'build_pack' => 'dockerfile', 'ports_exposes' => $port, 'name' => $name, 'instant_deploy' => false,
        ]);
        if (! $r->successful()) {
            Log::warning('Coolify app create failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);

            return [null, null];
        }
        $d = $r->json('domains');

        return [$r->json('uuid'), is_array($d) ? ($d[0] ?? null) : $d];
    }

    /** Inject client-provided credentials into the Coolify app's environment. */
    /** Point a subdomain under base_domain at the server (Cloudflare) + attach it to the Coolify app. */
    private function assignDomain(array $c, string $appUuid, string $subdomain): ?string
    {
        if (empty($c['cloudflare_token']) || empty($c['cloudflare_zone'])) {
            return null; // no Cloudflare configured → keep the default sslip.io domain
        }
        $sub = $subdomain;
        if (! $this->createDns($c, $sub)) {
            $sub = $subdomain.'-'.Str::lower(Str::random(4));
            if (! $this->createDns($c, $sub)) {
                return null;
            }
        }
        $fqdn = $sub.'.'.$c['base_domain'];
        try {
            $r = Http::withToken($c['coolify_token'])->timeout(30)
                ->patch($c['coolify_url']."/applications/{$appUuid}", ['domains' => "https://{$fqdn}"]);

            return $r->successful() ? "https://{$fqdn}" : null;
        } catch (\Throwable $e) {
            Log::warning('assignDomain failed', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /** Stable, collision-free production subdomain for a project: <slug>-<project uuid prefix>. */
    public function subdomainFor(Project $project): string
    {
        $slug = Str::slug($project->lead?->company ?: $project->lead?->name ?: 'sitio') ?: 'sitio';

        return $slug.'-'.substr((string) $project->uuid, 0, 6);
    }

    /** Stable, collision-free demo subdomain for a lead: <slug>-demo-<lead uuid prefix>. */
    public function demoSubdomain(Lead $lead): string
    {
        $slug = Str::slug($lead->company ?: $lead->name ?: 'sitio') ?: 'sitio';

        return $slug.'-demo-'.substr((string) $lead->uuid, 0, 6);
    }

    /** Reserve the domain (Cloudflare A record, proxied) early so DNS propagates during the build. */
    public function reserveDomain(string $subdomain): ?string
    {
        $c = config('overcloud.deploy');
        if (empty($c['cloudflare_token']) || empty($c['cloudflare_zone'])) {
            return null;
        }

        return $this->createDns($c, $subdomain) ? $subdomain.'.'.$c['base_domain'] : null;
    }

    /**
     * Delete ONLY genuine orphan Coolify apps: name matches $prefix, isn't the one we just
     * built ($keep), AND is not owned by any project in our DB. Never deletes a live client
     * app and never destroys volumes (irreversible) — a deploy must not be able to wipe
     * another client's site or data just because their slugs share a prefix.
     */
    private function removeStaleApps(array $c, string $prefix, string $keep): void
    {
        try {
            // Every app a project owns is OFF LIMITS, regardless of name.
            $owned = Project::whereNotNull('coolify_app_uuid')
                ->pluck('coolify_app_uuid')->filter()->all();

            $apps = Http::withToken($c['coolify_token'])->timeout(20)
                ->get($c['coolify_url'].'/applications')->json();
            if (! is_array($apps)) {
                return;
            }
            foreach ($apps as $a) {
                $n = $a['name'] ?? '';
                $uuid = $a['uuid'] ?? null;
                if (! $uuid || $n === $keep || ! Str::startsWith($n, $prefix) || in_array($uuid, $owned, true)) {
                    continue;
                }
                Http::withToken($c['coolify_token'])->timeout(25)
                    ->delete($c['coolify_url']."/applications/{$uuid}", ['delete_volumes' => false, 'delete_configurations' => true]);
                Log::info('removed orphan app', ['name' => $n]);
            }
        } catch (\Throwable $e) {
            Log::warning('removeStaleApps failed', ['e' => $e->getMessage()]);
        }
    }

    private function createDns(array $c, string $sub): bool
    {
        $fqdn = $sub.'.'.$c['base_domain'];
        try {
            $existing = Http::withToken($c['cloudflare_token'])->timeout(15)
                ->get("https://api.cloudflare.com/client/v4/zones/{$c['cloudflare_zone']}/dns_records", ['name' => $fqdn])
                ->json('result');
            // If it exists but points at a different server, UPDATE it to the current deploy IP
            // (otherwise the domain serves from the wrong server → 503).
            if (! empty($existing)) {
                $rec = $existing[0];
                if (($rec['content'] ?? '') !== $c['server_ip'] || ($rec['proxied'] ?? null) !== true) {
                    Http::withToken($c['cloudflare_token'])->timeout(20)
                        ->patch("https://api.cloudflare.com/client/v4/zones/{$c['cloudflare_zone']}/dns_records/{$rec['id']}", [
                            'content' => $c['server_ip'], 'proxied' => true, 'ttl' => 1,
                        ]);
                }

                return true;
            }

            return (bool) Http::withToken($c['cloudflare_token'])->timeout(20)
                ->post("https://api.cloudflare.com/client/v4/zones/{$c['cloudflare_zone']}/dns_records", [
                    'type' => 'A', 'name' => $sub, 'content' => $c['server_ip'], 'ttl' => 1, 'proxied' => true,
                ])->json('success');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Delete a subdomain's Cloudflare A record (used when freeing a lost lead's demo). */
    private function deleteDns(array $c, string $sub): void
    {
        if (empty($c['cloudflare_token']) || empty($c['cloudflare_zone'])) {
            return;
        }
        $fqdn = $sub.'.'.$c['base_domain'];
        try {
            $existing = Http::withToken($c['cloudflare_token'])->timeout(15)
                ->get("https://api.cloudflare.com/client/v4/zones/{$c['cloudflare_zone']}/dns_records", ['name' => $fqdn])
                ->json('result');
            foreach ((array) $existing as $rec) {
                if (! empty($rec['id'])) {
                    Http::withToken($c['cloudflare_token'])->timeout(15)
                        ->delete("https://api.cloudflare.com/client/v4/zones/{$c['cloudflare_zone']}/dns_records/{$rec['id']}");
                }
            }
        } catch (\Throwable $e) {
            Log::warning('deleteDns failed', ['fqdn' => $fqdn, 'e' => $e->getMessage()]);
        }
    }

    private function applyEnv(array $c, string $uuid, array $env): void
    {
        if (! $env) {
            return;
        }
        try {
            // Coolify's envs/bulk APPENDS — calling it for an existing key creates a duplicate line in
            // the container's .env (a real source of flaky config). Delete any existing copies of the
            // keys we're about to set first, so each key appears exactly once.
            $existing = Http::withToken($c['coolify_token'])->timeout(20)
                ->get($c['coolify_url']."/applications/{$uuid}/envs")->json();
            foreach ((is_array($existing) ? $existing : []) as $e) {
                if (isset($e['key'], $e['uuid']) && array_key_exists($e['key'], $env)) {
                    Http::withToken($c['coolify_token'])->timeout(15)
                        ->delete($c['coolify_url']."/applications/{$uuid}/envs/{$e['uuid']}");
                }
            }
        } catch (\Throwable $e) {
            // best-effort dedup; still try to set below
        }
        $data = ['data' => []];
        foreach ($env as $k => $v) {
            $data['data'][] = ['key' => (string) $k, 'value' => (string) $v, 'is_preview' => false];
        }
        try {
            Http::withToken($c['coolify_token'])->timeout(30)
                ->patch($c['coolify_url']."/applications/{$uuid}/envs/bulk", $data);
        } catch (\Throwable $e) {
            Log::warning('applyEnv failed', ['e' => $e->getMessage()]);
        }
    }

    private function triggerDeploy(array $c, string $uuid): ?string
    {
        return Http::withToken($c['coolify_token'])->timeout(40)
            ->get($c['coolify_url'].'/deploy', ['uuid' => $uuid, 'force' => true])
            ->json('deployments.0.deployment_uuid');
    }

    private function fetchLogs(array $c, ?string $depUuid): string
    {
        if (! $depUuid) {
            return '';
        }
        $logs = Http::withToken($c['coolify_token'])->timeout(20)
            ->get($c['coolify_url']."/deployments/{$depUuid}")->json('logs');

        return is_string($logs) ? $logs : (string) json_encode($logs);
    }

    /** Apps / SaaS / online stores → a REAL Laravel+Vue app (login, admin panel, Postgres data). */
    private function usesFullstack(Project $project): bool
    {
        // A WhatsApp bot product is a full-stack app too (it just clones the bot template).
        if ($this->usesBot($project)) {
            return true;
        }
        $brief = (array) ($project->brief ?? []);
        if (array_key_exists('fullstack', $brief)) {
            return (bool) $brief['fullstack'];
        }

        return in_array($project->lead?->service?->key, ['webapp', 'mobileapp', 'app', 'ecommerce'], true);
    }

    /** A WhatsApp-bot product: the client connects their own WhatsApp (Baileys QR) and the bot replies. */
    private function usesBot(Project $project): bool
    {
        $brief = (array) ($project->brief ?? []);
        if (array_key_exists('bot', $brief)) {
            return (bool) $brief['bot'];
        }
        // Infer from what the client asked for (the funnel's summary/spec).
        $needle = Str::lower(($project->lead?->summary ?? '').' '.($project->lead?->service_type ?? ''));

        return str_contains($needle, 'bot de whatsapp') || str_contains($needle, 'chatbot')
            || (str_contains($needle, 'bot') && str_contains($needle, 'whatsapp'));
    }

    /** Generate (once) and persist the admin login for a full-stack app; returns ['email','password']. */
    private function ensureAdminCreds(Project $project): array
    {
        $brief = (array) ($project->brief ?? []);
        $admin = (array) ($brief['admin'] ?? []);
        if (! empty($admin['email']) && ! empty($admin['password'])) {
            return $admin;
        }
        $slug = Str::slug($project->lead?->company ?: $project->lead?->name ?: 'cliente');
        $admin = [
            'email' => ($slug ?: 'admin').'@'.config('overcloud.deploy.base_domain', 'overcloud.us'),
            'password' => Str::password(12, true, true, false),
            'url' => null,
        ];
        $brief['admin'] = $admin;
        $project->update(['brief' => $brief]);

        return $admin;
    }

    /**
     * Make the Laravel app run migrations + seed (idempotent) on every container start, against the
     * Coolify-injected Postgres env, before serving — so the schema + admin user always exist and
     * data is permanent. Overwrites docker/start.sh with a known-good version.
     */
    private function ensureMigrateOnStart(string $dir): void
    {
        // The client template ships its OWN production entrypoint (docker/start.sh): a resilient
        // FrankenPHP boot that runs migrate + seed (best-effort, retrying in the background) before
        // serving on 8080 and never crashes the container on a slow DB or a bad request. We must NOT
        // overwrite it with a fragile `php artisan serve` script — trust the template's entrypoint.
    }

    /** Create a dedicated Postgres for this project and return its DB_* connection env (permanent data). */
    private function provisionDatabase(array $c, string $name): array
    {
        try {
            $r = Http::withToken($c['coolify_token'])->timeout(60)->post($c['coolify_url'].'/databases/postgresql', [
                'project_uuid' => $c['coolify_project'], 'server_uuid' => $c['coolify_server'],
                'environment_name' => 'production', 'name' => Str::limit($name, 40, '').'-db',
                'postgres_db' => 'app', 'postgres_user' => 'app', 'instant_deploy' => true,
            ]);
            $u = $r->json('internal_db_url'); // postgres://user:pass@host:5432/db
            if (! $u) {
                Log::warning('provisionDatabase: no internal_db_url', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);

                return [];
            }
            $p = parse_url($u);

            return [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $p['host'] ?? '',
                'DB_PORT' => (string) ($p['port'] ?? 5432),
                'DB_DATABASE' => ltrim($p['path'] ?? '/app', '/') ?: 'app',
                'DB_USERNAME' => rawurldecode($p['user'] ?? 'app'),
                'DB_PASSWORD' => rawurldecode($p['pass'] ?? ''),
                'DB_UUID' => (string) $r->json('uuid'),
            ];
        } catch (\Throwable $e) {
            Log::warning('provisionDatabase failed', ['e' => $e->getMessage()]);

            return [];
        }
    }

    /** Escalate to an on-the-moment Claude Code build when flagged custom or no starter exists. */
    private function usesCustomBuild(Project $project, array $stack): bool
    {
        $brief = (array) ($project->brief ?? []);
        if (array_key_exists('custom', $brief)) {
            return (bool) $brief['custom'];
        }
        if (empty($stack['repo'])) {
            return true;
        }

        // Real, functional projects are generated on-the-moment by Claude Code from the
        // scope; only a plain landing/marketing site uses the light content template.
        return ! in_array($project->lead?->service?->key, ['landing'], true);
    }

    private function createRepo(array $c, string $name): bool
    {
        $r = Http::withToken($c['github_token'])->post('https://api.github.com/user/repos', [
            'name' => $name, 'private' => false, 'auto_init' => false,
        ]);

        return $r->successful() || $r->status() === 422;
    }

    /** Push a locally-built project dir to a fresh GitHub repo using the token. */
    private function pushDir(array $c, string $dir, string $name): bool
    {
        $remote = "https://x-access-token:{$c['github_token']}@github.com/{$c['github_owner']}/{$name}.git";
        $clean = "https://github.com/{$c['github_owner']}/{$name}.git";
        // Inject the token only for the push, then scrub it back to a tokenless remote so the
        // PAT never lingers in a build dir that an untrusted agent might later read.
        $script = 'cd '.escapeshellarg($dir).' && git init -q && git add -A && '
            .'git -c user.email=bot@overcloud.us -c user.name=Overcloud commit -q -m build && '
            .'git branch -M main && (git remote add origin '.escapeshellarg($remote)
            .' 2>/dev/null || git remote set-url origin '.escapeshellarg($remote).') && '
            .'git push -q -u origin main --force; rc=$?; '
            .'git remote set-url origin '.escapeshellarg($clean).' 2>/dev/null; exit $rc';
        try {
            return Process::timeout(180)->run(['bash', '-lc', $script])->successful();
        } catch (\Throwable $e) {
            Log::warning('pushDir failed', ['e' => $e->getMessage()]);

            return false;
        }
    }

    /** Apply a client-requested change: clone -> Claude edits -> push -> redeploy -> verify. */
    public function applyChange(Project $project, string $instruction): bool
    {
        if (! $this->isConfigured() || ! $project->repo_url || ! $project->coolify_app_uuid) {
            return false;
        }
        $c = config('overcloud.deploy');
        $name = basename($project->repo_url);
        $dir = storage_path('builds/'.$name.'-chg-'.Str::lower(Str::random(4)));

        if (! $this->cloneRepo($c, $name, $dir)) {
            return false;
        }
        // Remember the known-good commit so a broken change can be rolled back off the LIVE site.
        $goodSha = $this->currentSha($dir);

        $this->reportChangeProgress($project, 1); // applying the changes
        if (! $this->agent->isAvailable()
            || ! $this->agent->change($project, $dir, $instruction)
            || ! $this->dirHasChanges($dir)   // the agent must have actually edited something (no silent no-op)
            || ! $this->pushDir($c, $dir, $name)) {
            return false;
        }

        $this->reportChangeProgress($project, 2); // publishing the update
        $stackKey = ((array) ($project->brief ?? []))['stack'] ?? $c['default_stack'];
        $stack = $c['stacks'][$stackKey] ?? $c['stacks'][$c['default_stack']];
        $content = ['business' => (string) ($project->lead?->company ?? '')];
        for ($i = 1; $i <= 3; $i++) {
            $dep = $this->triggerDeploy($c, $project->coolify_app_uuid);
            $this->reportChangeProgress($project, 3); // verifying it works live
            if ($this->waitForLive($c, $dep, $project->prod_url, $content, $stack)['ok']) {
                // Clear Cloudflare's edge cache so the client sees the change right away.
                $this->purgeCache($c, $project->prod_url);

                return true;
            }
        }

        // The change never verified live → restore the known-good version so the client is never
        // left with a broken/garbled site, then report failure (ApplyChange alerts the owner).
        if ($goodSha) {
            $this->rollback($c, $dir, $name, $goodSha, $project, $content, $stack);
        }

        return false;
    }

    /** Current HEAD sha of a checkout — the known-good commit before we apply a change. */
    private function currentSha(string $dir): ?string
    {
        try {
            $r = Process::timeout(30)->run(['bash', '-lc', 'git -C '.escapeshellarg($dir).' rev-parse HEAD']);

            return $r->successful() ? (trim($r->output()) ?: null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Whether the agent actually modified files (else the "change" is a no-op not worth deploying). */
    private function dirHasChanges(string $dir): bool
    {
        try {
            $r = Process::timeout(30)->run(['bash', '-lc', 'git -C '.escapeshellarg($dir).' status --porcelain']);

            return ! $r->successful() || trim($r->output()) !== ''; // can't tell → don't block
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** Restore the live site to a known-good commit after a failed change, and redeploy it. */
    private function rollback(array $c, string $dir, string $name, string $sha, Project $project, array $content, array $stack): void
    {
        $remote = "https://x-access-token:{$c['github_token']}@github.com/{$c['github_owner']}/{$name}.git";
        $clean = "https://github.com/{$c['github_owner']}/{$name}.git";
        $script = 'cd '.escapeshellarg($dir).' && (git remote add origin '.escapeshellarg($remote)
            .' 2>/dev/null || git remote set-url origin '.escapeshellarg($remote).') && '
            .'git push -q --force origin '.escapeshellarg($sha).':refs/heads/main; rc=$?; '
            .'git remote set-url origin '.escapeshellarg($clean).' 2>/dev/null; exit $rc';
        try {
            if (Process::timeout(120)->run(['bash', '-lc', $script])->successful()) {
                $dep = $this->triggerDeploy($c, $project->coolify_app_uuid);
                $this->waitForLive($c, $dep, $project->prod_url, $content, $stack);
                $this->purgeCache($c, $project->prod_url);
                Log::warning('applyChange rolled back to known-good', ['project' => $project->id, 'sha' => $sha]);
            }
        } catch (\Throwable $e) {
            Log::warning('rollback failed', ['project' => $project->id, 'e' => $e->getMessage()]);
        }
    }

    /** Purge the site's cached assets at Cloudflare so changes are visible immediately. */
    private function purgeCache(array $c, ?string $url): void
    {
        if (empty($c['cloudflare_token']) || empty($c['cloudflare_zone']) || ! $url) {
            return;
        }
        $base = rtrim($url, '/');
        $files = array_map(fn ($p) => $base.$p, [
            '/', '/index.html', '/styles.css', '/style.css', '/css/styles.css', '/css/style.css',
            '/script.js', '/main.js', '/app.js', '/js/script.js', '/js/main.js',
            '/menu.html', '/galeria.html', '/contacto.html', '/productos.html', '/tienda.html', '/nosotros.html',
        ]);
        try {
            Http::withToken($c['cloudflare_token'])->timeout(20)
                ->post("https://api.cloudflare.com/client/v4/zones/{$c['cloudflare_zone']}/purge_cache", ['files' => $files]);
        } catch (\Throwable $e) {
            Log::warning('purgeCache failed', ['e' => $e->getMessage()]);
        }
    }

    private function cloneRepo(array $c, string $name, string $dir): bool
    {
        $tokenRemote = "https://x-access-token:{$c['github_token']}@github.com/{$c['github_owner']}/{$name}.git";
        $cleanRemote = "https://github.com/{$c['github_owner']}/{$name}.git";
        try {
            // Clone with the token, then IMMEDIATELY strip it from .git/config — the
            // build dir is then handed to an untrusted, client-instructed Claude agent that
            // could otherwise read the org-wide PAT straight out of <dir>/.git/config.
            // chmod -R so the non-root 'builder' user can edit the cloned files.
            return Process::timeout(150)->run(['bash', '-lc',
                'rm -rf '.escapeshellarg($dir)
                .' && git clone -q '.escapeshellarg($tokenRemote).' '.escapeshellarg($dir)
                .' && git -C '.escapeshellarg($dir).' remote set-url origin '.escapeshellarg($cleanRemote)
                .' && chmod -R 0777 '.escapeshellarg($dir)])->successful();
        } catch (\Throwable $e) {
            Log::warning('cloneRepo failed', ['e' => $e->getMessage()]);

            return false;
        }
    }

    /** Steps shown on the client's live progress page during a full-stack build. */
    private const FS_STEPS = [
        'Preparando tu proyecto',
        'Desarrollando tu sistema (módulos, login y panel de administración)',
        'Creando tu base de datos',
        'Publicando tu sistema en línea',
        'Verificando que todo funcione',
        '¡Tu sistema está listo!',
    ];

    /** Steps shown on the client's progress page while a requested CHANGE is applied to a live site. */
    private const CHANGE_STEPS = [
        'Recibí tu cambio',
        'Aplicando los cambios a tu sistema',
        'Publicando la actualización en línea',
        'Verificando que todo funcione',
        '¡Tu cambio está listo!',
    ];

    /** Record build progress on the project (drives the live progress page) and optionally DM the client. */
    private function reportProgress(Project $project, int $idx, ?string $message = null, bool $done = false): void
    {
        try {
            $brief = (array) ($project->brief ?? []);
            $brief['progress'] = ['steps' => self::FS_STEPS, 'idx' => $idx, 'done' => $done, 'updated_at' => now()->toIso8601String()];
            $project->update(['brief' => $brief]);
        } catch (\Throwable $e) {
        }
        if ($message) {
            $this->notify($project, $message);
        }
    }

    /**
     * Drive the SAME live progress page for a requested change (kind='change' so the page doesn't
     * shortcut to "done" just because the project is already live). Used by applyChange + ApplyChange.
     */
    public function reportChangeProgress(Project $project, int $idx, bool $done = false): void
    {
        try {
            $brief = (array) ($project->fresh()->brief ?? []);
            $brief['progress'] = ['kind' => 'change', 'steps' => self::CHANGE_STEPS, 'idx' => $idx, 'done' => $done, 'failed' => false, 'updated_at' => now()->toIso8601String()];
            $project->update(['brief' => $brief]);
        } catch (\Throwable $e) {
        }
    }

    /** The public live-progress URL the client can open to watch their system being built or updated. */
    public function progressUrl(Project $project): string
    {
        return rtrim((string) config('app.url'), '/').'/progreso/'.$project->uuid;
    }

    /** Steps shown on the client's progress page while their pre-quote visual DEMO is being built. */
    private const DEMO_STEPS = [
        'Recibí tu visto bueno',
        'Diseñando tu demo con tu marca',
        'Publicándolo en línea',
        '¡Tu demo está listo!',
    ];

    /** Drive the live progress page for a pre-quote demo (keyed on the LEAD, which has no project yet). */
    public function reportDemoProgress(Lead $lead, int $idx, bool $done = false, bool $failed = false): void
    {
        try {
            $meta = (array) ($lead->fresh()->meta ?? []);
            $meta['progress'] = ['kind' => 'demo', 'steps' => self::DEMO_STEPS, 'idx' => $idx, 'done' => $done, 'failed' => $failed, 'updated_at' => now()->toIso8601String()];
            $lead->update(['meta' => $meta]);
        } catch (\Throwable $e) {
        }
    }

    /** The public live-progress URL for a lead's pre-quote demo. */
    public function progressUrlForLead(Lead $lead): string
    {
        return rtrim((string) config('app.url'), '/').'/progreso/'.$lead->uuid;
    }

    /**
     * Post-deploy end-to-end check the BOT runs against the LIVE url (not a human): the SPA shell
     * renders, compiled assets load over https (no blank page / mixed content), and /health proves
     * the app reached its database, ran migrations and seeded the admin. Returns ['ok'=>bool,'reason'].
     */
    private function verifyLiveApp(string $url): array
    {
        $url = rtrim($url, '/');
        try {
            // 1) Login shell renders (Inertia mount present).
            $login = Http::timeout(20)->get($url.'/login');
            if (! $login->successful() || ! str_contains($login->body(), 'id="app"')) {
                return ['ok' => false, 'reason' => 'login no renderiza ('.$login->status().')'];
            }
            // 2) A compiled asset referenced by the page loads over HTTPS (catches blank SPA / mixed content).
            if (preg_match('#https?://[^"\']+/build/assets/[\w.-]+\.js#', $login->body(), $m)) {
                if (str_starts_with($m[0], 'http://')) {
                    return ['ok' => false, 'reason' => 'assets en http (mixed content)'];
                }
                if (! Http::timeout(20)->get($m[0])->successful()) {
                    return ['ok' => false, 'reason' => 'assets 404'];
                }
            }
            // 3) App reached its DB + migrations + seed (admin user exists).
            $health = Http::timeout(20)->get($url.'/health');
            if (! $health->successful() || $health->json('ok') !== true || (int) $health->json('users') < 1) {
                return ['ok' => false, 'reason' => 'health/DB ('.$health->status().')'];
            }

            return ['ok' => true, 'reason' => 'ok'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => Str::limit($e->getMessage(), 80)];
        }
    }

    /** Best-effort progress message to the client's DM during the build/deploy. */
    private function notify(Project $project, string $message): void
    {
        try {
            $conv = $project->lead?->conversations()->where('is_group', false)->first();
            if ($conv && $conv->whatsappAccount) {
                $this->gateway->sendText($conv->whatsappAccount->session_name, $conv->contact_jid, $message);
            }
        } catch (\Throwable $e) {
            // progress is best-effort
        }
    }

    private function projectLabel(Project $project): string
    {
        return match ($project->lead?->service?->key) {
            'ecommerce' => 'tu tienda en línea',
            'mobileapp', 'app' => 'tu aplicación',
            'webapp' => 'tu plataforma',
            'landing' => 'tu landing',
            default => 'tu sitio web',
        };
    }

    private function fail(Project $project, string $why): ?string
    {
        // Never tell the client about errors — only progress. Alert the OWNER instead.
        $project->update(['status' => ProjectStatus::Review]);
        // Keep the live progress page honest (a soft "afinando" state) instead of frozen mid-build.
        try {
            $brief = (array) ($project->fresh()->brief ?? []);
            if (! empty($brief['progress'])) {
                $brief['progress']['failed'] = true;
                $brief['progress']['updated_at'] = now()->toIso8601String();
                $project->update(['brief' => $brief]);
            }
        } catch (\Throwable $e) {
        }
        Log::error('Autodeploy failed', ['project' => $project->id, 'why' => $why]);
        // Alert the owner ONCE per project (not on every retry / job re-run) — repeated identical
        // "deploy failed, manual review" pings are spam (and stay stale even after it later succeeds).
        $brief = (array) ($project->fresh()->brief ?? []);
        $alertedAt = $brief['alerted_at'] ?? null;
        if (! $alertedAt || now()->diffInHours(Carbon::parse($alertedAt)) >= 12) {
            $this->alertOwner('🚧 Falló el despliegue de "'.$this->projectLabel($project).'" — '.$why.'. Revísalo en el panel.');
            $project->update(['brief' => array_merge($brief, ['alerted_at' => now()->toIso8601String()])]);
        }

        return null;
    }

    /** Notify the owner (never the client) about a build/deploy problem. */
    public function alertOwner(string $message): void
    {
        try {
            $owner = (string) config('overcloud.owner_phone');
            $account = WhatsAppAccount::where('session_name', 'overcloud-bot')->first();
            if ($owner && $account) {
                $this->gateway->sendText($account->session_name, $owner.'@s.whatsapp.net', $message);
            }
        } catch (\Throwable $e) {
            Log::warning('alertOwner failed', ['e' => $e->getMessage()]);
        }
    }

    private function extractJson(?string $s): ?string
    {
        if (! $s) {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $s, $m)) {
            return $m[1];
        }
        if (preg_match('/\{.*\}/s', $s, $m)) {
            return $m[0];
        }

        return $s;
    }
}
