<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\DeployService;
use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies a client-requested change to a delivered project (clone -> edit -> redeploy)
 * and tells the client when it's live.
 */
class ApplyChange implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $projectId, public string $instruction) {}

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $project = Project::with('lead')->find($this->projectId);
        if (! $project) {
            return;
        }

        $ok = $deploy->applyChange($project, $this->instruction);

        $conv = $project->lead?->conversations()->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;
        if ($conv && $account) {
            $gateway->sendText($account->session_name, $conv->contact_jid, $ok
                ? "¡Listo! ✅ Ya apliqué el cambio en tu sitio:\n{$project->prod_url}\n\n¿Algo más en lo que te ayude? 🙌"
                : 'Tuve un detalle aplicando el cambio; mi equipo lo revisa y te confirmo enseguida. 🙏');
        }
    }
}
