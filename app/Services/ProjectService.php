<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\ProjectStatus;
use App\Enums\WhatsAppAccountStatus;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Quote;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provisions a project once its deposit is verified: creates the Project row and
 * a dedicated WhatsApp work group (owner + client + bot) where the bot handles
 * change requests. Best-effort — never blocks payment verification.
 */
class ProjectService
{
    public function __construct(private WhatsAppGateway $gateway) {}

    public function provisionFromQuote(Quote $quote): Project
    {
        $quote->loadMissing('lead.service');
        $lead = $quote->lead;

        $account = $this->account($lead);

        $project = Project::firstOrCreate(
            ['quote_id' => $quote->id],
            [
                'lead_id' => $lead->id,
                'maintenance_plan_id' => $quote->maintenance_plan_id,
                'whatsapp_account_id' => $account?->id,
                'name' => ($lead->service?->name ?? 'Proyecto').' · '.($lead->name ?? 'Cliente'),
                'slug' => Str::slug(($lead->name ?? 'proyecto').'-'.$quote->number),
                'type' => $lead->service?->key,
                'status' => ProjectStatus::Queued,
                'started_at' => now(),
            ]
        );

        if (! $project->whatsapp_group_jid && $account) {
            $this->createGroup($project, $account);
        }

        return $project->fresh();
    }

    private function account($lead): ?WhatsAppAccount
    {
        $id = $lead->conversations()->whereNotNull('whatsapp_account_id')->value('whatsapp_account_id');

        return ($id ? WhatsAppAccount::find($id) : null)
            ?? WhatsAppAccount::where('status', WhatsAppAccountStatus::Connected)->where('auto_reply', true)->first()
            ?? WhatsAppAccount::where('status', WhatsAppAccountStatus::Connected)->first();
    }

    private function createGroup(Project $project, WhatsAppAccount $account): void
    {
        if ($account->status !== WhatsAppAccountStatus::Connected) {
            Log::warning('Project group skipped: account not connected', ['project' => $project->id]);

            return;
        }

        $lead = $project->lead;
        $clientJid = $lead->conversations()->where('is_group', false)->value('contact_jid');
        $owner = (string) config('overcloud.company.owner_phone');
        $subject = 'Overcloud · '.($lead->name ?? 'Cliente').' · '.($lead->service?->name ?? 'Proyecto');

        try {
            // Create the group with NO participants. WhatsApp blocks DIRECTLY adding people who
            // aren't the bot's contacts (account_reachout_restricted), so instead we create an empty
            // group and send everyone a JOIN-BY-LINK invite, which is never blocked.
            $res = $this->gateway->createGroup($account->session_name, $subject, []);
            $jid = $res['jid'] ?? null;
            if (! $jid) {
                Log::warning('Group create returned no jid', ['project' => $project->id]);

                return;
            }

            $project->update(['whatsapp_group_jid' => $jid]);

            // The group thread where the bot answers change requests (ai_enabled).
            Conversation::updateOrCreate(
                ['whatsapp_account_id' => $account->id, 'contact_jid' => $jid],
                [
                    'lead_id' => $lead->id,
                    'contact_name' => $subject,
                    'is_group' => true,
                    'ai_enabled' => true,
                    'status' => ConversationStatus::Bot,
                ]
            );

            $welcome = "¡Bienvenidos a su grupo de proyecto en *Overcloud*! 🚀\n\n"
                ."Aquí coordinamos *{$subject}*. Escriban cualquier cambio, duda o material y lo atendemos por aquí. "
                .'Los ajustes dentro del alcance acordado son sin costo; si algo se sale del alcance, les enviamos una cotización antes de hacerlo. ✨';
            $this->gateway->sendText($account->session_name, $jid, $welcome);

            // Send the join link to the client (and owner) so they join themselves.
            $invite = $this->gateway->groupInvite($account->session_name, $jid);
            if ($invite) {
                if ($clientJid) {
                    $this->gateway->sendText($account->session_name, $clientJid,
                        "Te creé el *grupo de tu proyecto* 🚀 Únete aquí para que coordinemos todo por ahí:\n".$invite);
                }
                if ($owner) {
                    $this->gateway->sendText($account->session_name, $owner.'@s.whatsapp.net',
                        "Grupo de proyecto creado: {$subject}\nInvitación: ".$invite);
                }
            } else {
                Log::warning('Group invite link unavailable', ['project' => $project->id, 'group' => $jid]);
            }
        } catch (\Throwable $e) {
            Log::warning('Project group creation failed', ['project' => $project->id, 'e' => $e->getMessage()]);
        }
    }
}
