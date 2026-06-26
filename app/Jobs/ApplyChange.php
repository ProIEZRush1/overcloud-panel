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

    public int $timeout = 1800;

    /** Survive a worker restart (deploys): retry a killed change instead of stranding the client. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(45);
    }

    public function __construct(public int $projectId, public string $instruction) {}

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $project = Project::with('lead')->find($this->projectId);
        if (! $project) {
            return;
        }

        $ok = $deploy->applyChange($project, $this->instruction);

        // Only tell the client on success — never surface an error to them.
        $conv = $project->lead?->conversations()->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;
        if ($ok && $conv && $account) {
            $gateway->sendText($account->session_name, $conv->contact_jid,
                "¡Listo! ✅ Ya apliqué el cambio en tu sitio:\n{$project->prod_url}\n\n¿Algo más en lo que te ayude? 🙌");
        } elseif (! $ok) {
            // Alert the OWNER (not the client) so a human can apply the change.
            $deploy->alertOwner('🔧 No se pudo aplicar el cambio de "'.($project->name ?: $project->id).'": "'.$this->instruction.'". Hazlo manual.');
        }
    }
}
