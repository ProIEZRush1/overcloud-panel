<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Autonomously builds + deploys a client's Laravel+Vue site:
 *  1. AI generates the site content (content.json) from the confirmed scope.
 *  2. Generates a repo from the GitHub template (overcloud-client-template).
 *  3. Injects the content; the template is content-driven so no rebuild is needed.
 *  4. Creates a Coolify app (Dockerfile, fast composer-only build) and deploys it.
 *  5. Waits for the live URL and stores it on the project.
 */
class DeployService
{
    private const OWNER_WA = '5215594356241';

    public function __construct(private Assistant $assistant) {}

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
        $project->loadMissing('lead.service', 'quote.spec');

        $name = Str::slug(($project->lead?->company ?: $project->lead?->name ?: 'sitio')).'-'.Str::lower(Str::random(5));
        $content = $this->generateContent($project);

        if (! $this->generateRepo($c, $name)) {
            return null;
        }
        $this->updateContent($c, $name, $content);

        $url = $this->createApp($c, $name);
        if (! $url) {
            return null;
        }

        $live = $this->waitLive($url);
        $project->update([
            'prod_url' => $url,
            'repo_url' => "https://github.com/{$c['github_owner']}/{$name}",
            'status' => $live ? 'live' : 'building',
        ]);

        return $url;
    }

    private function generateContent(Project $project): array
    {
        $default = $this->defaultContent($project);
        if (! $this->assistant->isEnabled()) {
            return $default;
        }
        $lead = $project->lead;
        $spec = $project->quote?->spec ?? $lead?->latestSpec;
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
            $raw = $this->assistant->message($system, [['role' => 'user', 'content' => $ctx]]);
            $arr = json_decode($this->extractJson($raw) ?? '', true);
            if (is_array($arr) && ! empty($arr['business'])) {
                $arr = array_merge($default, $arr);
                $arr['built_by_whatsapp'] = self::OWNER_WA;
                // ensure product seeds exist for images
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

    private function generateRepo(array $c, string $name): bool
    {
        $r = Http::withToken($c['github_token'])->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$c['github_owner']}/{$c['template_repo']}/generate", [
                'owner' => $c['github_owner'], 'name' => $name, 'private' => false,
            ]);
        if (! $r->successful()) {
            Log::warning('Repo generate failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);

            return false;
        }
        sleep(2); // let GitHub settle the new repo

        return true;
    }

    private function updateContent(array $c, string $repo, array $content): void
    {
        $path = 'resources/content.json';
        $url = "https://api.github.com/repos/{$c['github_owner']}/{$repo}/contents/{$path}";
        $get = Http::withToken($c['github_token'])->get($url);
        $sha = $get->successful() ? $get->json('sha') : null;
        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Http::withToken($c['github_token'])->put($url, array_filter([
            'message' => 'Site content', 'content' => base64_encode($json), 'sha' => $sha,
        ]));
    }

    private function createApp(array $c, string $name): ?string
    {
        $r = Http::withToken($c['coolify_token'])->timeout(60)->post($c['coolify_url'].'/applications/public', [
            'project_uuid' => $c['coolify_project'], 'server_uuid' => $c['coolify_server'], 'environment_name' => 'production',
            'git_repository' => "https://github.com/{$c['github_owner']}/{$name}", 'git_branch' => 'main',
            'build_pack' => 'dockerfile', 'ports_exposes' => '8080', 'name' => $name, 'instant_deploy' => true,
        ]);
        if (! $r->successful()) {
            Log::warning('Coolify app create failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);

            return null;
        }
        $d = $r->json('domains');

        return is_array($d) ? ($d[0] ?? null) : $d;
    }

    private function waitLive(string $url): bool
    {
        for ($i = 0; $i < 22; $i++) {
            try {
                if (Http::timeout(8)->get($url)->successful()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // still building
            }
            sleep(9);
        }

        return false;
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
