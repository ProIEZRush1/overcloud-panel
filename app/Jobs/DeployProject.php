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
 * Builds + deploys the client's site after payment, then notifies them with the
 * live URL (DM + project group). Runs on the queue since it waits for the build.
 */
class DeployProject implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $projectId) {}

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $project = Project::with('lead')->find($this->projectId);
        if (! $project || ! $deploy->isConfigured()) {
            return;
        }

        $url = $deploy->deploy($project);
        if (! $url) {
            return;
        }

        $conv = $project->lead?->conversations()->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;
        if ($conv && $account) {
            $gateway->sendText($account->session_name, $conv->contact_jid,
                "¡Buenas noticias! 🚀 Tu sitio ya está en línea:\n{$url}\n\nRevísalo y cualquier ajuste me dices por aquí. 🙌");
        }
        if ($project->whatsapp_group_jid && $account) {
            try {
                $gateway->sendText($account->session_name, $project->whatsapp_group_jid, "🚀 ¡Sitio publicado! {$url}");
            } catch (\Throwable $e) {
                // group may be restricted
            }
        }
    }
}
