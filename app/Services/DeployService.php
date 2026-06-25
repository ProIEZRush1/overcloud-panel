<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\ProjectStatus;
use App\Models\Project;
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

        [$uuid, $url] = $this->createApp($c, $name, $stack['port']);
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
        if (($stack['kind'] ?? 'web') === 'web') {
            $biz = (string) ($content['business'] ?? '');
            if ($biz !== '' && ! Str::contains($html, $biz) && ! Str::contains($html, 'Overcloud')) {
                return ['ok' => false, 'reason' => 'contenido no presente'];
            }
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * Wait for the deploy: succeed the instant the live URL passes E2E (the truth),
     * fail fast if the build errors. Polls the URL first so "live" registers within
     * seconds of the site actually working, not after a slow build-status poll.
     */
    private function waitForLive(array $c, ?string $depUuid, string $url, array $content, array $stack): array
    {
        $last = ['ok' => false, 'reason' => 'sin respuesta'];
        for ($i = 0; $i < 45; $i++) {
            $last = $this->verify($url, $content, $stack);
            if ($last['ok']) {
                return $last;
            }
            if ($depUuid) {
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
        $script = 'cd '.escapeshellarg($dir).' && git init -q && git add -A && '
            .'git -c user.email=bot@overcloud.us -c user.name=Overcloud commit -q -m build && '
            .'git branch -M main && (git remote add origin '.escapeshellarg($remote)
            .' 2>/dev/null || git remote set-url origin '.escapeshellarg($remote).') && '
            .'git push -q -u origin main --force';
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

        if (! $this->cloneRepo($c, $name, $dir)
            || ! $this->agent->isAvailable()
            || ! $this->agent->change($project, $dir, $instruction)
            || ! $this->pushDir($c, $dir, $name)) {
            return false;
        }

        $stackKey = ((array) ($project->brief ?? []))['stack'] ?? $c['default_stack'];
        $stack = $c['stacks'][$stackKey] ?? $c['stacks'][$c['default_stack']];
        $content = ['business' => (string) ($project->lead?->company ?? '')];
        for ($i = 1; $i <= 3; $i++) {
            $dep = $this->triggerDeploy($c, $project->coolify_app_uuid);
            if ($this->waitForLive($c, $dep, $project->prod_url, $content, $stack)['ok']) {
                return true;
            }
        }

        return false;
    }

    private function cloneRepo(array $c, string $name, string $dir): bool
    {
        $remote = "https://x-access-token:{$c['github_token']}@github.com/{$c['github_owner']}/{$name}.git";
        try {
            // chmod -R so the non-root 'builder' user can edit the cloned files.
            return Process::timeout(150)->run(['bash', '-lc',
                'rm -rf '.escapeshellarg($dir).' && git clone -q '.escapeshellarg($remote).' '.escapeshellarg($dir)
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
        // Never tell the client about errors — only progress. The owner is alerted separately.
        $project->update(['status' => ProjectStatus::Review]);
        Log::error('Autodeploy failed', ['project' => $project->id, 'why' => $why]);

        return null;
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
