<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\ProjectStatus;
use App\Models\Lead;
use App\Models\Project;
use App\Models\WhatsAppAccount;
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

        $this->notify($project, "¡Manos a la obra! 🛠️ Empecé a construir {$label}. Te voy contando el avance por aquí. 🙌");

        $content = $this->generateContent($project);

        // Hybrid: fast template path by default; escalate to an on-the-moment Claude
        // Code build when the project is flagged custom or the stack has no starter.
        $custom = $this->usesCustomBuild($project, $stack);
        $dir = $custom ? storage_path('builds/'.$name) : null;
        if ($custom) {
            $this->notify($project, "Estoy desarrollando {$label} a la medida — diseño, funciones y panel de administración. Esto toma unos minutos. ⚙️");
            if (! $this->agent->isAvailable()
                || ! $this->agent->build($project, $stackKey, $content, $dir)
                || ! $this->createRepo($c, $name)
                || ! $this->pushDir($c, $dir, $name)) {
                return $this->fail($project, 'no se pudo construir el proyecto a la medida');
            }
        } else {
            $this->notify($project, "Estoy armando {$label} con tu diseño y contenido. 🎨");
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
            [$uuid, $url] = $this->createApp($c, $name, $stack['port']);
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

        // Inject any credentials the client shared (Stripe, API keys, …) into the app env.
        $this->applyEnv($c, $uuid, (array) ($brief['env'] ?? []));

        // Nice domain under overcloud.us (falls back to the default sslip.io if Cloudflare unset).
        // The subdomain is unique PER PROJECT (uuid suffix) so two clients with the same name
        // never share a URL — and stable across redeploys so a client's link never changes.
        $nice = $this->assignDomain($c, $uuid, $this->subdomainFor($project));
        if ($nice) {
            $url = $nice;
            $project->update(['prod_url' => $url, 'domain' => $url]);
        }

        $this->notify($project, "¡Ya casi! 🚀 Estoy publicando {$label} en línea y revisando que todo funcione bien...");

        // Self-heal: deploy -> wait for the build -> E2E verify the LIVE URL (the source
        // of truth, with retries for routing delay); retry the whole cycle on failure.
        for ($attempt = 1; $attempt <= (int) $c['max_attempts']; $attempt++) {
            $depUuid = $this->triggerDeploy($c, $uuid);

            $verdict = $this->waitForLive($c, $depUuid, $url, $content, $stack);
            if ($verdict['ok']) {
                $project->update(['status' => ProjectStatus::Live, 'delivered_at' => now()]);
                Log::info('Deploy live', ['project' => $project->id, 'url' => $url, 'attempt' => $attempt]);

                return $url;
            }

            Log::warning('Deploy attempt failed', ['project' => $project->id, 'attempt' => $attempt, 'reason' => $verdict['reason']]);
            if ($attempt < (int) $c['max_attempts']) {
                $this->notify($project, "Estoy afinando unos detalles para que {$label} quede perfecto. 🔧");
            }
            // On a custom build, let Claude Code repair the repo from the logs before retrying.
            if ($custom && $dir && $this->agent->repair($project, $stackKey, $dir, $this->fetchLogs($c, $depUuid))) {
                $this->pushDir($c, $dir, $name);
            }
            sleep(4);
        }

        return $this->fail($project, 'no pasó las pruebas tras varios intentos');
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

        if (! $this->agent->buildDemo($lead, $dir)
            || ! $this->createRepo($c, $name)
            || ! $this->pushDir($c, $dir, $name)) {
            return null;
        }

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

    private function applyEnv(array $c, string $uuid, array $env): void
    {
        if (! $env) {
            return;
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

        if (! $this->agent->isAvailable()
            || ! $this->agent->change($project, $dir, $instruction)
            || ! $this->dirHasChanges($dir)   // the agent must have actually edited something (no silent no-op)
            || ! $this->pushDir($c, $dir, $name)) {
            return false;
        }

        $stackKey = ((array) ($project->brief ?? []))['stack'] ?? $c['default_stack'];
        $stack = $c['stacks'][$stackKey] ?? $c['stacks'][$c['default_stack']];
        $content = ['business' => (string) ($project->lead?->company ?? '')];
        for ($i = 1; $i <= 3; $i++) {
            $dep = $this->triggerDeploy($c, $project->coolify_app_uuid);
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
        Log::error('Autodeploy failed', ['project' => $project->id, 'why' => $why]);
        $this->alertOwner('🚧 Falló el despliegue de "'.$this->projectLabel($project).'" — '.$why.'. Revísalo en el panel.');

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
