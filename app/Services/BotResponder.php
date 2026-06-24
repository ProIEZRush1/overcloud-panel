<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\QuoteStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Service;
use App\Models\ServiceFeature;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Drives the WhatsApp sales funnel end-to-end, stage by stage: greet → qualify →
 * scope + quote → accept → bank details → proof → "verifying, then your group".
 * Deterministic + reliable; Claude only polishes the conversational wording when
 * available (so the flow never breaks if Claude's creds lapse).
 */
class BotResponder
{
    public function __construct(
        private Assistant $assistant,
        private WhatsAppGateway $gateway,
        private SpecBuilder $specs,
        private QuoteBuilder $quotes,
        private PdfService $pdf,
        private PaymentService $payments,
    ) {}

    public function handle(Conversation $conversation, Message $inbound): ?Message
    {
        $conversation->loadMissing('lead.service', 'lead.quotes', 'whatsappAccount');
        if (! $conversation->botMayReply()) {
            return null;
        }

        if ($conversation->is_group) {
            return $this->send($conversation, $this->composeGroup($conversation)
                ?? 'Lo registro y el equipo lo revisa. 🙌');
        }

        return $this->funnel($conversation, $inbound);
    }

    private function funnel(Conversation $conversation, Message $inbound): ?Message
    {
        $lead = $conversation->lead;
        if (! $lead) {
            return null;
        }
        $text = Str::lower(trim($inbound->body ?? ''));
        $isMedia = in_array($inbound->type, [MessageType::Image, MessageType::Document], true);

        return match ($lead->stage) {
            LeadStage::New => $this->onNew($conversation, $lead),
            LeadStage::Qualifying => $this->onQualifying($conversation, $lead, $inbound, $text),
            LeadStage::Spec => $this->onScope($conversation, $lead, $text),
            LeadStage::Quoted, LeadStage::Negotiating => $this->onQuoted($conversation, $lead, $text),
            LeadStage::Accepted, LeadStage::AwaitingPayment => $this->onAwaitingPayment($conversation, $lead, $isMedia),
            default => $this->send($conversation, $this->claudeOr($conversation,
                'Tu proyecto ya está en marcha ✅ Cualquier cambio o duda lo vemos por aquí o en tu grupo. 🙌')),
        };
    }

    private function onNew(Conversation $conversation, Lead $lead): ?Message
    {
        $lead->update(['stage' => LeadStage::Qualifying]);

        return $this->send($conversation, $this->claudeOr($conversation,
            "¡Hola! 👋 Soy el asistente de *Overcloud*. Creamos páginas, sitios web, tiendas en línea y apps a precios accesibles.\n\n¿Qué te gustaría crear y para qué negocio?"));
    }

    private function onQualifying(Conversation $conversation, Lead $lead, Message $inbound, string $text): ?Message
    {
        $this->detectService($lead, $inbound->body ?? '');
        $lead = $lead->fresh();

        if ($lead->service_id && $this->wantsProposal($text)) {
            return $this->sendScope($conversation, $lead);
        }

        if (! $lead->service_id) {
            return $this->send($conversation, $this->claudeOr($conversation,
                'Con gusto 🙌 ¿Buscas una *página*, un *sitio web*, una *tienda en línea* o una *app*? Cuéntame un poco de tu negocio.'));
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            "¡Excelente! 🙌 Para armar tu propuesta cuéntame:\n• ¿Cuántas secciones o páginas?\n• ¿En cuántos idiomas?\n• ¿Ya tienes logo y textos?\n\nO si prefieres, te paso ya una propuesta base — solo dime *cotización*. ✅"));
    }

    private function onQuoted(Conversation $conversation, Lead $lead, string $text): ?Message
    {
        if ($this->isYes($text)) {
            return $this->sendBankDetails($conversation, $lead);
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            'Quedo al pendiente 🙌 Cuando me confirmes que la aprobamos, te paso los datos para el anticipo del 40% y arrancamos.'));
    }

    private function onAwaitingPayment(Conversation $conversation, Lead $lead, bool $isMedia): ?Message
    {
        if ($isMedia) {
            $lead->update(['stage' => LeadStage::AwaitingPayment]);

            return $this->send($conversation,
                '¡Recibí tu comprobante! 🙌 Verifico tu pago y, en cuanto quede aprobado, te creo tu *grupo de proyecto* y arrancamos. 🚀');
        }

        return $this->send($conversation,
            'Quedo al pendiente de tu *comprobante* (foto o PDF) para verificar el anticipo y arrancar. 🙌');
    }

    /** Scope stage: wait for the client to confirm the alcance before quoting. */
    private function onScope(Conversation $conversation, Lead $lead, string $text): ?Message
    {
        if ($this->isYes($text)) {
            return $this->sendQuote($conversation, $lead);
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            'Con gusto ajusto lo que necesites del alcance 🙌 ¿Qué te gustaría cambiar o agregar? En cuanto me confirmes que está a tu gusto, te preparo la *cotización*.'));
    }

    /** Generate + send ONLY the detailed scope doc; the quote comes after the client OKs it. */
    private function sendScope(Conversation $conversation, Lead $lead): ?Message
    {
        $spec = $this->specs->buildFromLead($lead);
        $this->pdf->renderSpec($spec);
        $lead->update(['stage' => LeadStage::Spec]);

        $this->send($conversation, '¡Perfecto! 🙌 Te preparé el *alcance detallado* de tu proyecto — objetivos, páginas, funciones, entregables y el proceso completo 📋');
        $this->sendDoc($conversation, $spec->fresh()->pdf_path, 'Alcance.pdf');

        return $this->send($conversation,
            'Revísalo con calma 🙌 Si está todo a tu gusto, *confírmame* y con eso te preparo la cotización 💰. Si quieres ajustar o agregar algo, dime y lo acomodo.');
    }

    /** Build + send the quote once the client confirmed the scope. */
    private function sendQuote(Conversation $conversation, Lead $lead): ?Message
    {
        $spec = $lead->specs()->latest()->first() ?? $this->specs->buildFromLead($lead);
        $quote = $this->quotes->buildFromLead($lead, $spec, [
            'feature_ids' => $this->defaultFeatures($lead->service),
            'pages' => $lead->pages ?: ($lead->service?->included_pages ?? 1),
            'languages' => max(1, count($lead->languages ?? ['es'])),
        ]);
        $this->pdf->renderQuote($quote);
        $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);
        $lead->update(['stage' => LeadStage::Quoted]);

        $m = fn ($v) => Money::format($v, $quote->currency);
        $this->send($conversation,
            "¡Excelente! 🙌 Con base en tu alcance, aquí tu *cotización* 💰\n\n*{$quote->number}*\n• Total: ".$m($quote->total_cents)."\n• 40% para iniciar: ".$m($quote->deposit_cents)."\n• 30% al desplegar + 30% en la entrega final\n• Mantenimiento: ".$m($quote->maintenance_monthly_cents)."/mes\n\n¿La aprobamos? ✅");

        return $this->sendDoc($conversation, $quote->fresh()->pdf_path, $quote->number.'.pdf');
    }

    /** Accept → create the 40% deposit and send bank details. */
    private function sendBankDetails(Conversation $conversation, Lead $lead): ?Message
    {
        $quote = $lead->quotes()->latest()->first();
        if (! $quote) {
            return $this->send($conversation, 'Permíteme preparar tu propuesta y te confirmo. 🙌');
        }
        $quote->update(['status' => QuoteStatus::Accepted, 'accepted_at' => now()]);
        $lead->update(['stage' => LeadStage::Accepted]);
        try {
            app(CrmSync::class)->syncQuoteAccepted($quote->fresh());
        } catch (\Throwable $e) {
        }

        $pr = $this->payments->createDeposit($quote->fresh());
        $snap = $pr->bank_details_snapshot ?? [];
        $m = fn ($v) => Money::format($v, $pr->currency);

        $msg = "¡Excelente decisión! 🎉 Para arrancar, transfiere el *40% de anticipo*: ".$m($pr->amount_cents)."\n\n";
        $msg .= '🏦 '.($snap['bank'] ?? '')."\n👤 ".($snap['beneficiary'] ?? '')."\n";
        if (! empty($snap['account_number'])) {
            $msg .= '#️⃣ Cuenta: '.$snap['account_number']."\n";
        }
        if (! empty($snap['clabe'])) {
            $msg .= '🔢 CLABE: '.$snap['clabe']."\n";
        }
        $msg .= '📝 Ref: '.$pr->reference."\n\nCuando transfieras, mándame el *comprobante* (foto o PDF) por aquí y verifico tu pago. 🙌";

        return $this->send($conversation, $msg);
    }

    private function defaultFeatures(?Service $service): array
    {
        $keys = match ($service?->key) {
            'ecommerce' => ['catalogo', 'carrito', 'pasarela_pago'],
            'webapp' => ['registro', 'panel_admin'],
            'mobileapp' => ['registro', 'panel_admin'],
            default => [],
        };

        return $keys ? ServiceFeature::whereIn('key', $keys)->pluck('id')->all() : [];
    }

    private function wantsProposal(string $text): bool
    {
        return Str::contains($text, ['cotiz', 'precio', 'costo', 'cuánto', 'cuanto', 'alcance', 'propuesta', 'presupuesto', 'cotización']);
    }

    private function isYes(string $text): bool
    {
        return Str::contains($text, ['aprob', 'acepto', 'adelante', 'dale', 'sí', 'claro', 'ok', 'okay', 'perfecto', 'me late', 'procede', 'va pues', 'hágale', 'hagale']);
    }

    private function detectService($lead, string $text): void
    {
        $text = Str::lower($text);
        $key = match (true) {
            Str::contains($text, ['tienda', 'ecommerce', 'e-commerce', 'venta', 'vender', 'productos', 'carrito']) => 'ecommerce',
            Str::contains($text, ['app movil', 'app móvil', 'aplicaci', 'android', 'ios']) => 'mobileapp',
            Str::contains($text, ['app', 'sistema', 'plataforma', 'panel', 'dashboard']) => 'webapp',
            Str::contains($text, ['sitio', 'multip', 'corporativo', 'institucional']) => 'website',
            Str::contains($text, ['landing', 'una pagina', 'una página', 'one page', 'aterrizaje']) => 'landing',
            Str::contains($text, ['web', 'pagina', 'página']) => 'website',
            default => null,
        };
        if ($key && ($service = Service::where('key', $key)->first())) {
            $lead->update(['service_id' => $service->id, 'service_type' => $service->name]);
        }
    }

    /** Claude wording when enabled+working, else the deterministic fallback. */
    private function claudeOr(Conversation $conversation, string $fallback): string
    {
        if (! $this->assistant->isEnabled()) {
            return $fallback;
        }
        try {
            return $this->composeWithClaude($conversation) ?: $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function composeWithClaude(Conversation $conversation): ?string
    {
        $history = $this->history($conversation);
        $system = 'Eres el asistente de ventas de Overcloud, una agencia que crea páginas, sitios web, tiendas en línea y apps a precios accesibles. '
            .'Hablas español, cálido, breve y profesional. Tu objetivo: entender qué necesita el cliente (tipo de proyecto, páginas, idiomas, si tiene logo/textos) para preparar una propuesta. '
            .'Haz una pregunta a la vez. No inventes precios. Si el cliente ya quiere la cotización, anímalo a que te diga "cotización".';

        return $this->assistant->message($system, $history);
    }

    private function composeGroup(Conversation $conversation): ?string
    {
        if (! $this->assistant->isEnabled()) {
            return null;
        }
        $proj = ($conversation->lead?->service?->name ?? 'su proyecto').' de '.($conversation->lead?->name ?? 'el cliente');
        $system = "Eres el asistente de proyecto de Overcloud en el grupo de WhatsApp de un cliente que YA contrató y pagó ({$proj}). "
            .'Hablas español, cálido, breve y profesional. Atiende solicitudes de cambios, dudas y materiales. '
            .'Si un cambio está dentro del alcance, confírmalo; si parece fuera del alcance, di amablemente que enviarás una cotización para ese extra antes de hacerlo. '
            .'No inventes fechas ni precios exactos.';
        try {
            return $this->assistant->message($system, $this->history($conversation));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function history(Conversation $conversation): array
    {
        return $conversation->messages()->latest()->limit(12)->get()->reverse()
            ->map(fn (Message $m) => [
                'role' => $m->is_from_me ? 'assistant' : 'user',
                'content' => $m->body ?? '['.$m->type->value.']',
            ])->values()->all();
    }

    private function send(Conversation $conversation, string $text): Message
    {
        $out = $conversation->messages()->create([
            'direction' => MessageDirection::Out,
            'type' => MessageType::Text,
            'body' => $text,
            'status' => MessageStatus::Pending,
            'is_from_me' => true,
            'ai_generated' => true,
            'wa_timestamp' => now(),
        ]);
        $conversation->update(['last_message_at' => now(), 'last_message_preview' => Str::limit($text, 120)]);
        try {
            $r = $this->gateway->sendText($conversation->whatsappAccount->session_name, $conversation->contact_jid, $text);
            $out->update(['status' => MessageStatus::Sent, 'wa_message_id' => $r['wa_message_id'] ?? null]);
        } catch (\Throwable $e) {
            $out->update(['status' => MessageStatus::Failed]);
        }

        return $out;
    }

    private function sendDoc(Conversation $conversation, ?string $path, string $filename): ?Message
    {
        if (! $path || ! Storage::exists($path)) {
            return null;
        }
        $out = $conversation->messages()->create([
            'direction' => MessageDirection::Out,
            'type' => MessageType::Document,
            'media_path' => $path,
            'media_filename' => $filename,
            'media_mime' => 'application/pdf',
            'status' => MessageStatus::Pending,
            'is_from_me' => true,
            'ai_generated' => true,
            'wa_timestamp' => now(),
        ]);
        try {
            $r = $this->gateway->sendMedia($conversation->whatsappAccount->session_name, $conversation->contact_jid, [
                'base64' => base64_encode(Storage::get($path)),
                'mimetype' => 'application/pdf',
                'fileName' => $filename,
                'kind' => 'document',
            ]);
            $out->update(['status' => MessageStatus::Sent, 'wa_message_id' => $r['wa_message_id'] ?? null]);
        } catch (\Throwable $e) {
            $out->update(['status' => MessageStatus::Failed]);
        }

        return $out;
    }
}
