<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\QuoteStatus;
use App\Jobs\ApplyChange;
use App\Jobs\DeployProject;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceFeature;
use App\Models\Spec;
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
            LeadStage::Paid => $this->onGathering($conversation, $lead, $inbound, $text),
            LeadStage::InProduction, LeadStage::Delivered, LeadStage::Maintenance => $this->onProduction($conversation, $lead, $text),
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

    /** Public: after payment, ask the client for everything we need to build (Overcloud-branded). */
    public function startGathering(Project $project): void
    {
        $conversation = Conversation::where('lead_id', $project->lead_id)->where('is_group', false)->first();
        if (! $conversation) {
            return;
        }
        $fallback = '¡Tu pago quedó verificado! ✅ Para construir tu proyecto necesito un par de cosas: el contenido y las fotos que quieras incluir, '
            .'los datos de tu negocio, y los accesos que apliquen (por ejemplo, para cobrar pagos en línea). '
            .'¿Me los pasas por aquí, te doy instrucciones, o prefieres que *yo me encargue de todo*? 🙌';
        $message = $fallback;
        if ($this->assistant->isEnabled()) {
            $spec = Spec::where('lead_id', $project->lead_id)->latest()->first();
            $feats = collect($spec?->content['features'] ?? [])->map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f)->filter()->implode(', ');
            $prompt = 'Eres el asistente de Overcloud, una agencia que construye sitios y aplicaciones. Acabamos de recibir el pago del cliente. '
                .'Escribe UN mensaje de WhatsApp, cálido y profesional, pidiéndole TODO lo que necesitas de él para construir su proyecto: contenido y textos, '
                .'fotos/logo, datos del negocio, y accesos o llaves si aplica (por ejemplo pasarela de pagos o servicios externos). '
                .'Ofrécele claramente 3 opciones: que te los pase, que le des instrucciones paso a paso, o que TÚ te encargas de todo por él. '
                .'NUNCA menciones herramientas internas ni proveedores de IA, ni plazos, fechas o tiempos de entrega. Habla como Overcloud. En español, breve. '
                .'Responde ÚNICAMENTE con el texto del mensaje, sin preámbulos como "Aquí tienes" ni separadores. '
                .'Proyecto: '.($spec?->title ?? 'su proyecto').'. Funciones: '.$feats.'.';
            $ai = $this->assistant->complete($prompt);
            $message = $ai ? $this->cleanMessage($ai) : $fallback;
        }
        $this->send($conversation, $message);
    }

    /** Strip meta-preambles ("Aquí tienes el mensaje:"), separators and wrapping quotes. */
    private function cleanMessage(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^\s*(aquí (tienes|está|va)\b[^\n:]*:?|claro[,!]?\s*aquí\b[^\n:]*:?)\s*\n+/iu', '', $text);
        $text = preg_replace('/^\s*-{3,}\s*\n+/', '', (string) $text);
        $text = preg_replace('/\n+\s*-{3,}\s*$/', '', (string) $text);

        return trim((string) $text, " \t\n\r\"");
    }

    /** Gathering: collect what the client shares; build when they say go / "do it all". */
    private function onGathering(Conversation $conversation, Lead $lead, Message $inbound, string $text): ?Message
    {
        $project = Project::where('lead_id', $lead->id)->latest()->first();
        if (! $project) {
            return $this->send($conversation, 'Dame un momentito, estoy preparando todo para arrancar. 🙌');
        }

        $brief = (array) ($project->brief ?? []);
        if (filled($inbound->body)) {
            $brief['requirements'][] = $inbound->body;

            // Capture any API keys/credentials the client shares — injected at deploy time.
            if ($this->looksLikeSecret($inbound->body)) {
                $env = $this->extractSecrets($inbound->body);
                if ($env) {
                    $brief['env'] = array_merge((array) ($brief['env'] ?? []), $env);
                    $project->update(['brief' => $brief]);

                    return $this->send($conversation, '¡Recibí tus accesos y los guardé de forma segura! 🔐 ¿Hay algo más o *arrancamos*?');
                }
            }
        }

        $all = $this->wantsAll($text);
        if ($all || $this->isYes($text)) {
            $brief['handle_all'] = $all;
            $project->update(['brief' => $brief]);
            $lead->update(['stage' => LeadStage::InProduction]);
            DeployProject::dispatch($project->id)->onQueue('deploy');

            return $this->send($conversation, $all
                ? '¡Perfecto! 🙌 Yo me encargo de *todo* — contenido, accesos y configuración. Empiezo a construirlo y te voy avisando del avance por aquí. 🛠️'
                : '¡Excelente! 🙌 Con eso arranco. Empiezo a construir tu proyecto y te voy contando el avance. 🛠️');
        }

        $project->update(['brief' => $brief]);

        return $this->send($conversation, $this->claudeOr($conversation,
            '¡Gracias, lo anoto! 🙌 ¿Hay algo más que quieras incluir o *arrancamos*? Si prefieres, también me puedo encargar yo de todo — solo dime.'));
    }

    /** After delivery: apply change requests, otherwise stay available. */
    private function onProduction(Conversation $conversation, Lead $lead, string $text): ?Message
    {
        $project = Project::where('lead_id', $lead->id)->latest()->first();
        if ($project && $project->repo_url && $project->coolify_app_uuid && $this->looksLikeChange($text)) {
            ApplyChange::dispatch($project->id, $text)->onQueue('deploy');

            return $this->send($conversation, '¡Claro! 🙌 Aplico ese cambio en tu sitio y te aviso en cuanto quede actualizado. 🔧');
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            'Tu proyecto ya está en línea ✅ Si quieres algún ajuste o cambio, descríbemelo y lo aplico. 🙌'));
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
        $this->captureLeadDetails($conversation, $lead);
        $spec = $this->specs->buildFromLead($lead->fresh());
        $this->pdf->renderSpec($spec);
        $lead->update(['stage' => LeadStage::Spec]);

        $this->send($conversation, '¡Perfecto! 🙌 Te preparé el *alcance detallado* de tu proyecto — objetivos, páginas, funciones, entregables y el proceso completo 📋');
        $this->sendDoc($conversation, $spec->fresh()->pdf_path, 'Alcance.pdf');

        return $this->send($conversation,
            'Revísalo con calma 🙌 Si está todo a tu gusto, *confírmame* y con eso te preparo la cotización 💰. Si quieres ajustar o agregar algo, dime y lo acomodo.');
    }

    /** Extract the business name + need from the chat (once) so the scope, quote and site are tailored. */
    private function captureLeadDetails(Conversation $conversation, Lead $lead): void
    {
        if ($lead->company && $lead->summary) {
            return;
        }
        $chat = $conversation->messages()->where('is_from_me', false)->latest()->take(10)->get()
            ->reverse()->pluck('body')->filter()->implode("\n");
        if ($chat === '') {
            return;
        }
        try {
            $raw = $this->assistant->complete(
                'Extrae datos del negocio del cliente de esta conversación de WhatsApp. Responde SOLO con JSON válido: '
                .'{"company":"<nombre del negocio, o vacío si no lo dijo>","summary":"<en 1-2 frases qué quiere construir>"}.'
                ."\n\nConversación:\n".$chat
            );
            if (preg_match('/\{.*\}/s', (string) $raw, $m) && is_array($data = json_decode($m[0], true))) {
                $lead->update(array_filter([
                    'company' => $lead->company ?: ($data['company'] ?? null),
                    'summary' => $lead->summary ?: ($data['summary'] ?? null),
                ]));
            }
        } catch (\Throwable $e) {
            // best-effort; scope/content fall back to generic
        }
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

        $this->send($conversation,
            "¡Excelente! 🙌 Con base en tu alcance, aquí tu *cotización* 💰\n\nEn el PDF (*{$quote->number}*) vienen todos los detalles y el plan de pagos. Revísala con calma y, si te late, *apruébala* para arrancar. ✅");

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

        $msg = '¡Excelente decisión! 🎉 Para arrancar, transfiere el *40% de anticipo*: '.$m($pr->amount_cents)."\n\n";
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
        return Str::contains($text, ['aprob', 'aprueb', 'acept', 'adelante', 'dale', 'sí', 'si,', 'claro', 'ok', 'okay', 'perfecto', 'me late', 'procede', 'de acuerdo', 'va pues', 'hágale', 'hagale', 'confirm']);
    }

    /** Client wants us to handle everything ("hazlo tú", "encárgate de todo"). */
    private function wantsAll(string $text): bool
    {
        return Str::contains($text, ['hazlo tú', 'hazlo tu', 'hazlo todo', 'haz todo', 'encárgate', 'encargate', 'tú te encargas', 'tu te encargas', 'de todo', 'tú hazlo', 'tu hazlo', 'has todo', 'encargate de todo', 'me encargo no', 'hazlo por mí', 'hazlo por mi']);
    }

    /** Heuristic: the client is describing a change to the delivered site. */
    private function looksLikeChange(string $text): bool
    {
        return Str::contains($text, ['cambi', 'cámbi', 'agrega', 'añade', 'anade', 'quita', 'pon ', 'ponle', 'modific', 'color', 'logo', 'texto', 'precio', 'producto', 'foto', 'imagen', 'ajusta', 'mueve', 'actualiza', 'reemplaza', 'más grande', 'mas grande', 'más chico']);
    }

    /** The message likely contains API keys/credentials the client is sharing. */
    private function looksLikeSecret(string $text): bool
    {
        return (bool) preg_match('/(sk_[a-z]+_|pk_[a-z]+_|sk-[A-Za-z0-9]{8}|AIza[0-9A-Za-z\-_]{8}|whsec_|rk_live|[A-Za-z0-9_\-]{28,})/', $text)
            || Str::contains(Str::lower($text), ['stripe', 'api key', 'apikey', 'api_key', 'mi llave', 'mi clave', 'token de', 'secret key']);
    }

    /** Extract credentials from the message as ENV pairs via the assistant. */
    private function extractSecrets(string $text): array
    {
        if (! $this->assistant->isEnabled()) {
            return [];
        }
        try {
            $raw = $this->assistant->complete(
                'Extrae credenciales o llaves de API del siguiente texto de WhatsApp. Responde SOLO con JSON {"NOMBRE_ENV":"valor"} '
                .'usando nombres estándar en mayúsculas (STRIPE_SECRET, STRIPE_KEY, OPENAI_API_KEY, etc.). Si no hay ninguna, responde {}.'
                ."\n\n".$text
            );
            if (preg_match('/\{.*\}/s', (string) $raw, $m) && is_array($d = json_decode($m[0], true))) {
                return array_filter($d, fn ($v) => is_string($v) && trim($v) !== '');
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        return [];
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
            .'Haz una pregunta a la vez. No inventes precios. Si el cliente ya quiere la cotización, anímalo a que te diga "cotización". '
            .'Si pregunta por tiempos de entrega: la entrega es muy rápida, el proyecto queda listo en 1 día o menos, y los cambios o correcciones se hacen cuando el cliente los pida. Nunca menciones plazos de semanas.';

        $reply = $this->assistant->message($system, $history);

        return $reply ? $this->cleanMessage($reply) : null;
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
