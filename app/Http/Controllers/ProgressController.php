<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Setting;

class ProgressController extends Controller
{
    /** Public live build-progress page for a client (no auth — shared by link). Works for a project
     *  (build/change) AND a lead's pre-quote demo (no project yet) — both keyed by uuid. */
    public function show(string $uuid)
    {
        $project = Project::where('uuid', $uuid)->first();
        if ($project) {
            return $this->render(
                (array) ($project->brief['progress'] ?? []),
                $project->name ?: 'Tu proyecto',
                $project->lead?->company ?: ($project->lead?->name ?: 'tu negocio'),
                $project->status?->value === 'live',
                fn () => $project->prod_url,
            );
        }
        // A pre-quote DEMO lives on the lead (it has no project yet); its progress is in lead.meta.
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $progress = (array) ($lead->meta['progress'] ?? []);

        return $this->render(
            $progress,
            'Tu demo',
            $lead->company ?: ($lead->name ?: 'tu negocio'),
            false,
            fn () => $progress['url'] ?? null,
        );
    }

    private function render(array $progress, string $title, string $business, bool $statusLive, \Closure $urlResolver)
    {
        $steps = $progress['steps'] ?? [
            'Preparando tu proyecto',
            'Desarrollando tu sistema (módulos, login y panel de administración)',
            'Creando tu base de datos',
            'Publicando tu sistema en línea',
            'Verificando que todo funcione',
            '¡Tu sistema está listo!',
        ];
        $current = (int) ($progress['idx'] ?? 0);
        // A change/demo runs on/for an already-live account, so don't let status=live shortcut it.
        $shortcut = $statusLive && ! in_array($progress['kind'] ?? '', ['change', 'demo'], true);
        $done = (bool) ($progress['done'] ?? false) || $shortcut;
        $failed = ! $done && (bool) ($progress['failed'] ?? false);
        $pct = $done ? 100 : (int) min(96, round(($current / max(1, count($steps) - 1)) * 100));

        return view('progress', [
            'title' => $title,
            'business' => $business,
            'steps' => $steps,
            'current' => $current,
            'done' => $done,
            'failed' => $failed,
            'pct' => $pct,
            'url' => $done ? $urlResolver() : null,
            'brand' => [
                'primary' => Setting::get('brand_primary', '#7c3aed'),
                'accent' => Setting::get('brand_accent', '#c026d3'),
            ],
        ]);
    }
}
