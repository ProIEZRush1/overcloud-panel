<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;

class ProgressController extends Controller
{
    /** Public live build-progress page for a client (no auth — shared by link). */
    public function show(string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $progress = (array) ($project->brief['progress'] ?? []);
        $steps = $progress['steps'] ?? [
            'Preparando tu proyecto',
            'Desarrollando tu sistema (módulos, login y panel de administración)',
            'Creando tu base de datos',
            'Publicando tu sistema en línea',
            'Verificando que todo funcione',
            '¡Tu sistema está listo!',
        ];
        $current = (int) ($progress['idx'] ?? 0);
        // A change runs on an already-live site, so don't let status=live shortcut it to "done".
        $isChange = ($progress['kind'] ?? '') === 'change';
        $done = (bool) ($progress['done'] ?? false) || (! $isChange && $project->status?->value === 'live');
        $failed = ! $done && (bool) ($progress['failed'] ?? false);
        $pct = $done ? 100 : (int) min(96, round(($current / max(1, count($steps) - 1)) * 100));

        return view('progress', [
            'title' => $project->name ?: 'Tu proyecto',
            'business' => $project->lead?->company ?: ($project->lead?->name ?: 'tu negocio'),
            'steps' => $steps,
            'current' => $current,
            'done' => $done,
            'failed' => $failed,
            'pct' => $pct,
            'url' => $done ? $project->prod_url : null,
            'brand' => [
                'primary' => Setting::get('brand_primary', '#7c3aed'),
                'accent' => Setting::get('brand_accent', '#c026d3'),
            ],
        ]);
    }
}
