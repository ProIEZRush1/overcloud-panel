<?php

namespace App\Jobs;

use App\Enums\LeadStage;
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

    public int $timeout = 1800;

    public function __construct(public int $projectId) {}

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $project = Project::with('lead')->find($this->projectId);
        if (! $project || ! $deploy->isConfigured()) {
            return;
        }

        $conv = $project->lead?->conversations()->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;

        $url = $deploy->deploy($project);

        // Deploy failed E2E after retries — alert the owner, don't bother the client.
        if (! $url) {
            if ($account) {
                $owner = config('overcloud.company.owner_phone');
                $gateway->sendText($account->session_name, $owner.'@s.whatsapp.net',
                    "⚠️ El despliegue del proyecto *{$project->name}* no pasó las pruebas tras varios intentos. Requiere revisión manual.");
            }

            return;
        }

        // Delivered: subsequent client messages route to change-handling.
        $project->lead?->update(['stage' => LeadStage::Delivered]);

        if ($conv && $account) {
            $gateway->sendText($account->session_name, $conv->contact_jid,
                "¡Buenas noticias! 🚀 Tu sitio ya está en línea:\n{$url}\n\nRevísalo y, si quieres cualquier cambio, descríbemelo por aquí y lo aplico. 🙌");
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
