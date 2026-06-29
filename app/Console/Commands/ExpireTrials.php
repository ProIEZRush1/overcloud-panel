<?php

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Project;
use App\Services\DeployService;
use App\Services\WhatsAppGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the 5-day trial policy: every demo lives 5 days. ~24h before it lapses the client gets one
 * reminder; once the 5 days pass with no payment the Coolify service (app + dedicated Postgres + DNS) is
 * torn down to free resources. The Project row stays in the panel (Cancelled) so the lead/history remains.
 * When the client pays, brief['paid'] is set and the trial is skipped here (it becomes the full version).
 */
class ExpireTrials extends Command
{
    protected $signature = 'trials:expire';

    protected $description = 'Remind + tear down unpaid 5-day trial demos (keeps the panel record).';

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): int
    {
        $trials = Project::with('lead')
            ->whereIn('status', [ProjectStatus::Live->value, ProjectStatus::Review->value, ProjectStatus::Building->value])
            ->get()
            ->filter(fn (Project $p) => ($p->brief['trial'] ?? false)
                && empty($p->brief['paid'])
                && empty($p->brief['trial_expired_at']));

        foreach ($trials as $project) {
            $brief = (array) $project->brief;
            $expiresAt = ! empty($brief['trial_expires_at'])
                ? Carbon::parse($brief['trial_expires_at'])
                : optional($project->delivered_at)->copy()?->addDays(5);
            if (! $expiresAt) {
                continue;
            }

            if (now()->greaterThanOrEqualTo($expiresAt)) {
                $deploy->expireTrialInfra($project);
                $brief['trial_expired_at'] = now()->toIso8601String();
                $project->update(['status' => ProjectStatus::Cancelled, 'brief' => $brief]);
                $this->notifyClient($gateway, $project,
                    'Tu *demo* de 5 días terminó y la cerré por ahora. 🙂 Si quieres reactivarla y dejarla '
                    .'*fija y completa* (sin perder nada), escríbeme por aquí y la vuelvo a activar en minutos.');
                $deploy->alertOwner('⌛ Demo expirado (5 días sin pago): "'.($project->lead?->name ?: ('proyecto '.$project->id)).'". Servicio Coolify eliminado; registro conservado en el panel.');
                Log::info('trial expired + torn down', ['project' => $project->id]);

                continue;
            }

            // One reminder in the last ~24h before it lapses.
            if (empty($brief['expiry_reminded']) && now()->greaterThanOrEqualTo($expiresAt->copy()->subDay())) {
                $this->notifyClient($gateway, $project,
                    '⏳ Tu *demo* termina mañana. Si te gustó cómo funciona, con tu pago la dejamos *fija y '
                    .'completa* y no pierdes nada de lo que ya configuraste. ¿La activamos? 🙌');
                $brief['expiry_reminded'] = true;
                $project->update(['brief' => $brief]);
            }
        }

        return self::SUCCESS;
    }

    private function notifyClient(WhatsAppGateway $gateway, Project $project, string $message): void
    {
        $conv = Conversation::where('lead_id', $project->lead_id)->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;
        if ($conv && $account) {
            try {
                $gateway->sendText($account->session_name, $conv->contact_jid, $message);
            } catch (\Throwable $e) {
                Log::warning('trial notify failed', ['project' => $project->id, 'e' => $e->getMessage()]);
            }
        }
    }
}
