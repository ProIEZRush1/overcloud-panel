<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Service;
use Illuminate\Support\Str;

/**
 * Decides and sends the automated reply for an inbound message. Uses Claude when a
 * key is configured, otherwise a deterministic Spanish intake script.
 */
class BotResponder
{
    public function __construct(
        private ClaudeClient $claude,
        private WhatsAppGateway $gateway,
    ) {}

    public function handle(Conversation $conversation, Message $inbound): ?Message
    {
        $conversation->loadMissing('lead', 'whatsappAccount');
        if (! $conversation->botMayReply() || ! $conversation->whatsappAccount?->auto_reply) {
            return null;
        }

        $reply = $this->compose($conversation, $inbound);
        if (! $reply) {
            return null;
        }

        $outbound = $conversation->messages()->create([
            'direction' => \App\Enums\MessageDirection::Out,
            'type' => MessageType::Text,
            'body' => $reply,
            'status' => MessageStatus::Pending,
            'is_from_me' => true,
            'ai_generated' => true,
            'wa_timestamp' => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($reply, 120),
        ]);

        $this->dispatchToGateway($conversation, $reply, $outbound);

        return $outbound;
    }

    private function compose(Conversation $conversation, Message $inbound): ?string
    {
        if ($ai = $this->composeWithClaude($conversation)) {
            return $ai;
        }

        return $this->composeDeterministic($conversation, $inbound);
    }

    private function composeWithClaude(Conversation $conversation): ?string
    {
        if (! $this->claude->isEnabled()) {
            return null;
        }
        $history = $conversation->messages()->latest()->limit(12)->get()->reverse()
            ->map(fn (Message $m) => [
                'role' => $m->is_from_me ? 'assistant' : 'user',
                'content' => $m->body ?? '['.$m->type->value.']',
            ])->values()->all();

        $system = "Eres el asistente de ventas de Overcloud, una agencia que crea páginas, sitios web, tiendas en línea y apps a precios accesibles. "
            ."Hablas español, eres cálido, breve y profesional. Tu objetivo: entender qué necesita el cliente, qué tipo de proyecto, cuántas páginas e idiomas, "
            ."si tiene logo/textos y para cuándo lo necesita, para luego preparar una propuesta. Haz una pregunta a la vez. No inventes precios todavía.";

        return $this->claude->message($system, $history);
    }

    private function composeDeterministic(Conversation $conversation, Message $inbound): string
    {
        $lead = $conversation->lead;

        if ($lead && $lead->stage === LeadStage::New) {
            $lead->update(['stage' => LeadStage::Qualifying]);

            return "¡Hola! 👋 Soy el asistente de *Overcloud*. Creamos páginas, sitios web, tiendas en línea y apps a precios accesibles.\n\n"
                .'¿Qué te gustaría crear? (una *página*, un *sitio web*, una *tienda en línea* o una *app*)';
        }

        if ($lead && $lead->stage === LeadStage::Qualifying) {
            $this->detectService($lead, $inbound->body ?? '');

            return "¡Excelente elección! 🙌 Para armarte una propuesta a la medida, cuéntame:\n"
                ."• ¿Cuántas secciones o páginas necesitas?\n"
                ."• ¿En cuántos idiomas?\n"
                .'• ¿Ya tienes logo y textos, y para cuándo lo necesitas?';
        }

        return 'Gracias, lo registro. En breve te comparto la propuesta detallada. 🙌';
    }

    private function detectService($lead, string $text): void
    {
        $text = Str::lower($text);
        $key = match (true) {
            Str::contains($text, ['tienda', 'ecommerce', 'e-commerce', 'venta', 'productos']) => 'ecommerce',
            Str::contains($text, ['app', 'aplicaci']) => 'webapp',
            Str::contains($text, ['sitio', 'web', 'multip']) => 'website',
            Str::contains($text, ['landing', 'pagina', 'página', 'one page']) => 'landing',
            default => null,
        };
        if ($key && ($service = Service::where('key', $key)->first())) {
            $lead->update(['service_id' => $service->id, 'service_type' => $service->name]);
        }
    }

    private function dispatchToGateway(Conversation $conversation, string $reply, Message $outbound): void
    {
        try {
            $result = $this->gateway->sendText(
                $conversation->whatsappAccount->session_name,
                $conversation->contact_jid,
                $reply,
            );
            $outbound->update([
                'status' => MessageStatus::Sent,
                'wa_message_id' => $result['wa_message_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Gateway may be offline (e.g. during tests); keep the message queued.
            $outbound->update(['status' => MessageStatus::Failed]);
        }
    }
}
