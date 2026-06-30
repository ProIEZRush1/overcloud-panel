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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

        // Serialize changes per project: if another change/deploy is in flight, retry shortly
        // (retryUntil keeps it alive) instead of two jobs racing the same repo + Coolify app.
        $lock = Cache::lock('deploy-project:'.$this->projectId, 1800);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $label = $project->name ?: ('#'.$project->id);

            // Always tell the owner an autonomous change touched a live site — on BOTH outcomes —
            // so a misheard/unintended auto-change can never happen invisibly (it did before).
            $deploy->alertOwner('🔧 Cambio en "'.$label.'": "'.$this->instruction.'". Aplicándolo a '.($project->prod_url ?: 's/u').' …');

            $ok = $deploy->applyChange($project, $this->instruction);

            // Only tell the client on success — never surface an error to them.
            $conv = $project->lead?->conversations()->where('is_group', false)->first();
            $account = $conv?->whatsappAccount;
            if ($ok && $conv && $account) {
                $deploy->reportChangeProgress($project, 4, true); // progress page → "¡Tu cambio está listo!"
                // ALWAYS tell the client WHAT changed + HOW to access it (the agent wrote the summary).
                $summary = trim((string) ($project->fresh()->brief['change_summary'] ?? ''));
                $what = $summary !== '' ? "\n\n{$summary}" : '';
                $gateway->sendText($account->session_name, $conv->contact_jid,
                    "¡Listo! ✅ Ya apliqué tu cambio.{$what}\n\n🌐 Para verlo, inicia sesión en tu sistema:\n{$project->prod_url}\n\n¿Algo más en lo que te ayude? 🙌");
                $deploy->alertOwner('✅ Cambio aplicado en "'.$label.'": "'.$this->instruction.'". → '.$project->prod_url);
            } elseif (! $ok) {
                // Keep the progress page honest (soft "still working" state, not frozen) + alert the OWNER.
                try {
                    $b = (array) $project->fresh()->brief;
                    $b['progress']['failed'] = true;
                    $project->update(['brief' => $b]);
                } catch (\Throwable $e) {
                }
                $deploy->alertOwner('⚠️ No se pudo aplicar el cambio de "'.$label.'": "'.$this->instruction.'". Hazlo manual.');
            }
        } finally {
            $lock->release();
        }
    }

    /** A killed/exception change still alerts the owner so a promised change never silently vanishes. */
    public function failed(\Throwable $e): void
    {
        try {
            app(DeployService::class)->alertOwner(
                '⚠️ El cambio del proyecto #'.$this->projectId.' falló: '.Str::limit($e->getMessage(), 160).'. Aplícalo manual.'
            );
        } catch (\Throwable $ignored) {
        }
    }
}
