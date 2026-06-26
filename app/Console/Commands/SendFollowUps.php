<?php

namespace App\Console\Commands;

use App\Enums\ConversationStatus;
use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Services\WhatsAppGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends ONE gentle follow-up reminder to a lead who went silent after the bot's last message,
 * so warm prospects who simply stopped replying get re-engaged exactly once (never spammed).
 * Runs daily from the scheduler (a reasonable hour, not the middle of the night).
 */
class SendFollowUps extends Command
{
    protected $signature = 'bot:followups';

    protected $description = 'Send one reminder to leads who stopped answering mid-funnel';

    /** Funnel stages worth nudging (active sale; not closed, in production, delivered, or lost). */
    private const ACTIVE_STAGES = [
        LeadStage::New, LeadStage::Qualifying, LeadStage::Spec, LeadStage::Negotiating,
        LeadStage::Quoted, LeadStage::Accepted, LeadStage::AwaitingPayment,
    ];

    public function handle(WhatsAppGateway $gateway): int
    {
        $sent = 0;

        Conversation::query()
            ->where('is_group', false)
            ->where('ai_enabled', true)
            ->where('status', ConversationStatus::Bot)
            ->whereNotNull('last_message_at')
            ->whereBetween('last_message_at', [now()->subDays(7), now()->subHours(24)])
            ->whereHas('lead', fn ($q) => $q->whereIn('stage', array_map(fn ($s) => $s->value, self::ACTIVE_STAGES)))
            ->with(['lead', 'whatsappAccount'])
            ->get()
            ->each(function (Conversation $conv) use ($gateway, &$sent) {
                // Only nudge when the CLIENT went silent — i.e. our message was the last one.
                $last = $conv->messages()->latest('id')->first();
                if (! $last || ! $last->is_from_me) {
                    return;
                }

                // Exactly ONE reminder per silent period: don't remind again until the client writes.
                $lastInboundId = (int) $conv->messages()->where('is_from_me', false)->max('id');
                $meta = (array) ($conv->meta ?? []);
                if ((int) ($meta['followup_after_inbound_id'] ?? -1) === $lastInboundId) {
                    return;
                }

                if ($this->sendReminder($conv, $this->reminderFor($conv->lead?->stage), $gateway)) {
                    $meta['followup_after_inbound_id'] = $lastInboundId; // mark only after a confirmed send
                    $conv->meta = $meta;
                    $conv->save();
                    $sent++;
                }
            });

        $this->info("Follow-ups sent: {$sent}");

        return self::SUCCESS;
    }

    /** A warm, stage-aware nudge (Overcloud voice; never mentions tooling or errors). */
    private function reminderFor(?LeadStage $stage): string
    {
        return match ($stage) {
            LeadStage::New, LeadStage::Qualifying => '¡Hola! 👋 ¿Seguimos con tu proyecto? Cuéntame qué te gustaría crear y te preparo todo a tu medida. 🙌',
            LeadStage::Spec => '¡Hola! 🙌 ¿Pudiste revisar el *alcance* que te preparé? Si está a tu gusto, confírmame y seguimos con tu *demo* visual. 🎨',
            LeadStage::Negotiating => '¡Hola! 🙌 ¿Qué te pareció tu *demo*? Si te gustó, te paso la *cotización* y arrancamos. Cualquier ajuste, con gusto lo vemos. 🎨',
            LeadStage::Quoted => '¡Hola! 🙌 ¿Pudiste ver tu *cotización*? Si te late, confírmame y arrancamos con tu proyecto enseguida. 🚀 Cualquier duda, aquí estoy.',
            LeadStage::Accepted, LeadStage::AwaitingPayment => '¡Hola! 🙌 Estamos a un paso de arrancar. Cuando tengas listo el *comprobante* del anticipo, mándamelo y empiezo con tu proyecto. 🚀',
            default => '¡Hola! 🙌 Quedé al pendiente de tu proyecto. ¿Seguimos? Aquí estoy para lo que necesites.',
        };
    }

    private function sendReminder(Conversation $conv, string $text, WhatsAppGateway $gateway): bool
    {
        if (! $conv->whatsappAccount) {
            return false;
        }
        $out = $conv->messages()->create([
            'direction' => MessageDirection::Out,
            'type' => MessageType::Text,
            'body' => $text,
            'status' => MessageStatus::Pending,
            'is_from_me' => true,
            'ai_generated' => true,
            'wa_timestamp' => now(),
        ]);
        $conv->update(['last_message_at' => now(), 'last_message_preview' => Str::limit($text, 120)]);
        try {
            $r = $gateway->sendText($conv->whatsappAccount->session_name, $conv->contact_jid, $text);
            $out->update(['status' => MessageStatus::Sent, 'wa_message_id' => $r['wa_message_id'] ?? null]);

            return true;
        } catch (\Throwable $e) {
            $out->update(['status' => MessageStatus::Failed]);
            Log::warning('follow-up send failed', ['conv' => $conv->id, 'e' => $e->getMessage()]);

            return false;
        }
    }
}
