<?php

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Lead;
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
                && empty($p->brief['comped']) // comped/free partner builds are NOT trials — never tear them down
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

        $this->expireDemos($deploy, $gateway);

        return self::SUCCESS;
    }

    /** Pre-quote DEMOS live on the lead (meta.demo) with no project — same 5-day tear-down rule. */
    private function expireDemos(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $leads = Lead::whereNotNull('meta')->get()->filter(function (Lead $l) {
            $demo = (array) ($l->meta['demo'] ?? []);

            return ! empty($demo['expires_at']) && empty($demo['expired_at'])
                // Skip leads that already converted to an active (paid/live) project.
                && ! Project::where('lead_id', $l->id)->whereIn('status', ['live', 'maintenance', 'building'])->exists();
        });

        foreach ($leads as $lead) {
            $meta = (array) $lead->meta;
            $expiresAt = Carbon::parse($meta['demo']['expires_at']);

            if (now()->greaterThanOrEqualTo($expiresAt)) {
                $deploy->removeDemo($lead);
                $meta['demo']['expired_at'] = now()->toIso8601String();
                $meta['progress'] = null;
                $lead->update(['meta' => $meta]);
                $this->notifyLead($gateway, $lead,
                    'Tu *demo* de 5 días terminó y lo cerré por ahora. 🙂 Si quieres retomarlo y arrancar tu proyecto, escríbeme y lo reactivo enseguida.');
                $deploy->alertOwner('⌛ Demo (lead) expirado a los 5 días: "'.($lead->company ?: $lead->name ?: ('lead '.$lead->id)).'". Eliminado; registro conservado.');
                Log::info('lead demo expired + torn down', ['lead' => $lead->id]);

                continue;
            }

            if (empty($meta['demo']['reminded']) && now()->greaterThanOrEqualTo($expiresAt->copy()->subDay())) {
                $this->notifyLead($gateway, $lead,
                    '⏳ Tu *demo* termina mañana. Si te gustó cómo se ve, dime y te paso la *cotización* para dejarlo fijo y arrancar. 🙌');
                $meta['demo']['reminded'] = true;
                $lead->update(['meta' => $meta]);
            }
        }
    }

    private function notifyLead(WhatsAppGateway $gateway, Lead $lead, string $message): void
    {
        $conv = Conversation::where('lead_id', $lead->id)->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;
        if ($conv && $account) {
            try {
                $gateway->sendText($account->session_name, $conv->contact_jid, $message);
            } catch (\Throwable $e) {
                Log::warning('demo expiry notify failed', ['lead' => $lead->id, 'e' => $e->getMessage()]);
            }
        }
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
