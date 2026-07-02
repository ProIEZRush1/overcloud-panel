<?php

namespace App\Jobs;

use App\Enums\LeadStage;
use App\Models\Project;
use App\Services\BillingService;
use App\Services\DeployService;
use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Builds + deploys the client's site after payment, then notifies them with the
 * live URL (DM + project group). Runs on the queue since it waits for the build.
 */
class DeployProject implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // A full first build (Laravel + Vue store with modules, assets, DB provision + deploy) can
    // legitimately run well over 45 min. Give it real headroom so a large build never gets
    // SIGKILLed mid-flight, and so it survives sitting in the queue behind another build.
    public int $timeout = 5400;

    /** No two concurrent builds for the same project (a double-dispatch is a no-op duplicate). */
    public int $uniqueFor = 7200;

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    /** Survive a worker restart + a long wait in the queue: retry a killed build instead of stranding the client. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(120);
    }

    public function __construct(public int $projectId) {}

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $project = Project::with('lead')->find($this->projectId);
        if (! $project || ! $deploy->isConfigured()) {
            return;
        }

        // Mutually exclusive with ApplyChange (shared lock key) so an initial build and a change
        // can never push the same repo / trigger the same Coolify app at the same time.
        $lock = Cache::lock('deploy-project:'.$this->projectId, 5400);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
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

            // Hand over the site + admin access and charge the 30% milestone (7-day window + changes).
            app(BillingService::class)->onDeployed($project);
            if ($project->whatsapp_group_jid && $account) {
                try {
                    $gateway->sendText($account->session_name, $project->whatsapp_group_jid, "🚀 ¡Sitio publicado! {$url}");
                } catch (\Throwable $e) {
                    // group may be restricted
                }
            }
        } finally {
            $lock->release();
        }
    }
}
