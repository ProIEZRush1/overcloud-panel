<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Enums\ConversationStatus;
use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuoteStatus;
use App\Jobs\ApplyChange;
use App\Jobs\DeployProject;
use App\Jobs\RemoveDemo;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PaymentProposal;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceFeature;
use App\Models\Spec;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
                ?? '¡Recibido! 🙌 Lo reviso y te respondo aquí mismo en un momento.');
        }

        return $this->funnel($conversation, $inbound);
    }

    /** Send a one-off notice to the client (respecting the bot-enabled gate) without running the funnel. */
    public function notice(Conversation $conversation, string $text): ?Message
    {
        if (! $conversation->botMayReply()) {
            return null;
        }

        return $this->send($conversation, $text);
    }

    private function funnel(Conversation $conversation, Message $inbound): ?Message
    {
        $lead = $conversation->lead;
        if (! $lead) {
            return null;
        }
        $text = Str::lower(trim($inbound->body ?? ''));
        $isMedia = in_array($inbound->type, [MessageType::Image, MessageType::Document], true);

        // A live-site change captured from a voice note is held until the client confirms it
        // verbatim. A clean "sí" applies it; anything else cancels it and is handled normally.
        if ($pending = $conversation->pendingChange()) {
            if ($this->isYes($text)) {
                return $this->dispatchConfirmedChange($conversation, $lead, $pending);
            }
            $conversation->clearPendingChange();
        }

        // The client wants a real person / doesn't want a bot → hand them off to the owner (never
        // lose them, never delete anything). This takes precedence over the "lost" close.
        if ($this->wantsHuman($text)) {
            return $this->handoffToOwner($conversation, $lead, $text);
        }

        // The client clearly lost interest / declined while still in the sales funnel → close it
        // gracefully, mark the lead Lost, free their demo resources, and stop messaging — but KEEP
        // the full record (lead + conversation) in the panel.
        $activeSale = in_array($lead->stage, [LeadStage::Qualifying, LeadStage::Spec, LeadStage::Negotiating, LeadStage::Quoted, LeadStage::New], true);
        if ($activeSale && $this->looksLost($text)) {
            return $this->closeLost($conversation, $lead, $text);
        }

        // AI-driven routing for the SALES funnel: the assistant reads the whole conversation and
        // decides when to ADVANCE (prepare the alcance, build the demo, send the quote, pass payment
        // data) vs simply answer a question — so it never promises a step the system doesn't take and
        // isn't blocked by brittle keyword matching. Falls back to the deterministic handlers below
        // whenever the AI is unavailable or unsure, so the funnel never breaks if Claude's creds lapse.
        if ($this->assistant->isEnabled()
            && in_array($lead->stage, [LeadStage::Qualifying, LeadStage::Spec, LeadStage::Negotiating, LeadStage::Quoted], true)) {
            if ($routed = $this->aiRoute($conversation, $lead, $inbound)) {
                return $routed;
            }
        }

        // Post-delivery SUPPORT: a delivered client asking a question must get a real answer (not the
        // canned "tu proyecto ya está en línea"); a change request goes to the live-site change flow.
        if ($this->assistant->isEnabled()
            && in_array($lead->stage, [LeadStage::InProduction, LeadStage::Delivered, LeadStage::Maintenance, LeadStage::Review], true)) {
            if ($routed = $this->aiSupport($conversation, $lead, $inbound)) {
                return $routed;
            }
        }

        return match ($lead->stage) {
            LeadStage::New => $this->onNew($conversation, $lead),
            LeadStage::Qualifying => $this->onQualifying($conversation, $lead, $inbound, $text),
            LeadStage::Spec => $this->onScope($conversation, $lead, $text),
            LeadStage::Negotiating => $this->onDemo($conversation, $lead, $text),
            LeadStage::Quoted => $this->onQuoted($conversation, $lead, $text),
            LeadStage::Accepted, LeadStage::AwaitingPayment => $this->onAwaitingPayment($conversation, $lead, $isMedia, $text),
            LeadStage::Paid => $this->onGathering($conversation, $lead, $inbound, $text),
            LeadStage::InProduction, LeadStage::Delivered, LeadStage::Maintenance, LeadStage::Review => $this->onProduction($conversation, $lead, $inbound, $text),
            LeadStage::Lost => $this->send($conversation, $this->claudeOr($conversation,
                '¡Qué gusto saludarte de nuevo! 🙌 ¿Retomamos tu proyecto? Cuéntame qué necesitas y seguimos.')),
            default => $this->send($conversation, $this->claudeOr($conversation,
                'Tu proyecto ya está en marcha ✅ Cualquier cambio o duda lo vemos por aquí o en tu grupo. 🙌')),
        };
    }

    /** Stage → the actions the AI may choose, with guidance. Only SALES steps (never deploys/payments). */
    private const FUNNEL_ACTIONS = [
        'qualifying' => [
            'advance' => 'Ya tienes una idea general de QUÉ tipo de proyecto quiere y para qué negocio (aunque falten detalles menores), o el cliente respondió tus preguntas → prepárale su *alcance* AHORA. No sigas preguntando de más.',
            'ask' => 'Todavía NO tienes idea de qué tipo de proyecto es → haz 1 pregunta breve para entenderlo.',
        ],
        'spec' => [
            'advance' => 'El cliente está conforme con el alcance o pide que avancemos → arma su *demo* visual.',
            'adjust' => 'El cliente pide cambios o agrega información al alcance → tómalo en cuenta.',
            'answer' => 'El cliente hace una pregunta o comenta algo → respóndele claramente, sin avanzar todavía.',
        ],
        'negotiating' => [
            'advance' => 'Al cliente le gustó el demo, lo elogia ("está increíble", "me encanta", "wow"), o pregunta cómo contratarlo / cómo pagar → mándale su *cotización* (es la señal de compra; no te quedes solo respondiendo).',
            'adjust' => 'El cliente pide cambios concretos al demo → tómalo en cuenta (se reconstruye).',
            'answer' => 'El cliente tiene una duda sobre el PRODUCTO que NO es aprobación ni sobre cómo pagar → respóndele, sin avanzar.',
        ],
        'quoted' => [
            'advance' => 'El cliente APRUEBA la cotización, dice que quiere arrancar, o pregunta cómo/dónde pagar → pásale los datos para el anticipo.',
            'propose' => 'El cliente propone otra forma de pago o pide un descuento → regístralo.',
            'answer' => 'El cliente tiene una duda sobre el PRODUCTO o el alcance (qué incluye, qué es X, cómo funciona algo) → respóndele bien, SIN avanzar al pago.',
        ],
    ];

    /** Ask the assistant to choose the next funnel action + craft the reply. Null → use the fallback. */
    private function aiDecide(Conversation $conversation, Lead $lead): ?array
    {
        $actions = self::FUNNEL_ACTIONS[$lead->stage->value] ?? null;
        if (! $actions) {
            return null;
        }
        $actionList = collect($actions)->map(fn ($desc, $key) => "- \"{$key}\": {$desc}")->implode("\n");
        $transcript = collect($this->history($conversation))
            ->map(fn ($m) => ($m['role'] === 'assistant' ? 'Asistente' : 'Cliente').': '.$m['content'])
            ->implode("\n");
        $wantsService = $lead->stage === LeadStage::Qualifying;

        $prompt = $this->salesPersona()
            ."\n\nEs una conversación de WhatsApp. ETAPA ACTUAL: {$lead->stage->value}. Decide la ÚNICA mejor acción siguiente entre estas:\n"
            .$actionList
            ."\n\nResponde ÚNICAMENTE con JSON válido (sin ```): {\"action\":\"<una de las claves de arriba>\",\"reply\":\"<el mensaje de WhatsApp para el cliente, cálido y breve>\""
            .($wantsService ? ',"service":"<landing|website|ecommerce|webapp|mobileapp>"' : '')
            .'}. El "reply" es lo que se le ENVÍA al cliente. Si la acción AVANZA (prepara alcance, demo, cotización o datos de pago), el sistema enviará además el documento correspondiente, así que NO inventes precios ni listas: usa el reply solo para confirmar con naturalidad.'
            ."\n\nConversación:\n".$transcript;

        try {
            $raw = $this->assistant->complete($prompt);
            if (! preg_match('/\{.*\}/s', (string) $raw, $m) || ! is_array($d = json_decode($m[0], true))) {
                return null;
            }
            if (empty($d['action']) || ! array_key_exists($d['action'], $actions)) {
                return null;
            }

            return $d;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Execute the AI's chosen action. Advancing steps reuse the proven deterministic doc flows. */
    private function aiRoute(Conversation $conversation, Lead $lead, Message $inbound): ?Message
    {
        $d = $this->aiDecide($conversation, $lead);
        if (! $d) {
            return null; // AI down or unsure → deterministic fallback
        }
        $action = $d['action'];
        $reply = isset($d['reply']) ? $this->cleanMessage((string) $d['reply']) : '';
        $body = (string) ($inbound->body ?? '');
        $say = fn (): ?Message => $reply !== '' ? $this->send($conversation, $reply) : null;

        switch ($lead->stage) {
            case LeadStage::Qualifying:
                if ($action === 'advance') {
                    $this->ensureService($lead, $d['service'] ?? null);

                    return $this->sendScope($conversation, $lead->fresh());
                }

                return $say();

            case LeadStage::Spec:
                if ($action === 'advance') {
                    return $this->startDemo($conversation, $lead);
                }
                if ($action === 'adjust') {
                    $this->captureFeedback($lead, $body);
                }

                return $say();

            case LeadStage::Negotiating:
                if ($action === 'advance') {
                    return $this->sendQuote($conversation, $lead);
                }
                if ($action === 'adjust') {
                    $this->captureFeedback($lead, $body);
                    $this->rebuildDemoWithFeedback($lead, $body);
                }

                return $say();

            case LeadStage::Quoted:
                if ($action === 'advance') {
                    return $this->sendBankDetails($conversation, $lead);
                }
                if ($action === 'propose') {
                    return $this->recordPaymentProposal($conversation, $lead, $body);
                }

                return $say();

            default:
                return null;
        }
    }

    /** Make sure the lead has a service before scoping — use the AI's classification, else webapp. */
    private function ensureService(Lead $lead, ?string $key): void
    {
        if ($lead->service_id) {
            return;
        }
        $key = in_array($key, ['landing', 'website', 'ecommerce', 'webapp', 'mobileapp'], true) ? $key : 'webapp';
        if ($service = Service::where('key', $key)->first()) {
            $lead->update(['service_id' => $service->id, 'service_type' => $service->name]);
        }
    }

    /**
     * Post-delivery SUPPORT routing: the assistant decides whether the delivered client is asking a
     * QUESTION (answer it) or requesting a CHANGE (route to the live-site change flow). Answers come
     * from a support persona that never reveals the AI and points to the project group for changes.
     * Returns null when the AI is unavailable/unsure → the deterministic onProduction handles it.
     */
    private function aiSupport(Conversation $conversation, Lead $lead, Message $inbound): ?Message
    {
        $transcript = collect($this->history($conversation))
            ->map(fn ($m) => ($m['role'] === 'assistant' ? 'Asistente' : 'Cliente').': '.$m['content'])
            ->implode("\n");
        $prompt = $this->supportPersona()
            ."\n\nEl cliente YA tiene su proyecto entregado y en línea. Decide la ÚNICA mejor acción:\n"
            ."- \"change\": el cliente pide un cambio, ajuste, contenido nuevo o una función para su sitio/sistema → se aplica.\n"
            ."- \"answer\": el cliente hace una pregunta o comenta (cómo usarlo, cómo abrirlo, una duda, qué tecnología, etc.) → respóndele bien y con calidez.\n\n"
            .'Responde ÚNICAMENTE con JSON válido: {"action":"change|answer","reply":"<mensaje de WhatsApp para el cliente>"}. '
            .'El "reply" es lo que se le ENVÍA. Si la acción es "change", confirma con naturalidad que lo aplicas (el sistema lo construye y le avisa); NO pidas que repita nada.'
            ."\n\nConversación:\n".$transcript;

        $reply = '';
        $action = 'answer';
        try {
            $raw = $this->assistant->complete($prompt);
            if (preg_match('/\{.*\}/s', (string) $raw, $m) && is_array($d = json_decode($m[0], true)) && ! empty($d['action'])) {
                $action = $d['action'] === 'change' ? 'change' : 'answer';
                $reply = isset($d['reply']) ? $this->cleanMessage((string) $d['reply']) : '';
            }
        } catch (\Throwable $e) {
            // AI hiccup → fall through to the safe deterministic handling below (never canned).
        }

        $text = Str::lower(trim($inbound->body ?? ''));

        // It's a CHANGE when the AI says so OR the deterministic matcher does — but NEVER for a
        // question (so "¿cómo abro?"/"¿qué IA usa?" are always answered, never auto-applied).
        $isQuestion = Str::endsWith($text, '?') || Str::startsWith($text, ['¿', 'que ', 'qué ', 'como ', 'cómo ', 'cuando', 'cuándo', 'cuanto', 'cuánto', 'donde', 'dónde', 'por que', 'por qué', 'puedo ', 'se puede', 'podrias', 'podrías', 'podemos', 'cual', 'cuál', 'quien', 'quién']);
        $wantsChange = ! $isQuestion && ($action === 'change' || $this->looksLikeChange($text));

        // A change captured from a VOICE NOTE is always confirmed before touching the live site.
        if ($inbound->type === MessageType::Audio && $wantsChange) {
            $conversation->setPendingChange(trim((string) $inbound->body));

            return $this->send($conversation,
                "Entendí que quieres este cambio:\n«".trim((string) $inbound->body)."»\n\n¿Lo aplico a tu sistema? Respóndeme *sí* para confirmar. 🙌");
        }

        // A real, actionable text change → dispatch it.
        if ($wantsChange) {
            return $this->dispatchConfirmedChange($conversation, $lead, trim((string) $inbound->body));
        }

        // Otherwise ANSWER — the AI's reply, or a warm non-canned fallback (never the robotic line).
        if ($reply !== '') {
            return $this->send($conversation, $reply);
        }
        $project = Project::where('lead_id', $lead->id)->latest('id')->first();
        $url = $project?->prod_url;

        return $this->send($conversation,
            '¡Claro! 🙌 Tu sistema ya está en línea'.($url ? " en:\n{$url}" : '.')
            ."\n\n¿Qué te gustaría saber o ajustar? Si quieres un cambio, descríbemelo y lo aplico; cualquier duda, aquí estoy. 😊");
    }

    /** Support persona for delivered clients: warm, never reveals the AI, points changes to the group. */
    private function supportPersona(): string
    {
        return 'Eres el asistente de soporte de *Overcloud*, una agencia que construye sitios, tiendas y aplicaciones. '
            .'El cliente ya tiene su proyecto entregado y EN LÍNEA. Hablas español, cálido, claro y breve. '
            .'Tu trabajo: responder sus dudas (cómo abrir/usar su sistema, cómo funciona algo, cómo hacer cambios) y tomar sus solicitudes de cambios. '
            .'Para abrir su proyecto: solo abre el enlace (su URL) en cualquier navegador. Para hacer cambios o personalizarlo: que te los pida por aquí o en su *grupo de proyecto*, y los aplicas. '
            .'Recuérdale, cuando venga al caso, que el *grupo* es justamente para coordinar cambios y mejoras de su sistema. '
            .$this->aiSecrecyRule();
    }

    /** Hard rule: never reveal the AI/LLM behind the bot — deflect to "tecnología propia de Overcloud". */
    private function aiSecrecyRule(): string
    {
        return 'PROHIBIDO ABSOLUTO: NUNCA reveles, nombres ni des a entender qué inteligencia artificial, modelo, proveedor o herramienta usas (no menciones IA, Claude, Anthropic, GPT, OpenAI, ChatGPT, "modelo de lenguaje", etc.). '
            .'Si te preguntan qué IA o tecnología usa el bot, responde con naturalidad que es *tecnología y desarrollo propios de Overcloud* y ofrece seguir ayudando. Nunca confirmes que eres un bot o una IA.';
    }

    private function onNew(Conversation $conversation, Lead $lead): ?Message
    {
        $lead->update(['stage' => LeadStage::Qualifying]);

        return $this->send($conversation, $this->claudeOr($conversation,
            "¡Hola! 👋 Soy el asistente de *Overcloud*. Creamos páginas, sitios web, tiendas en línea y apps a precios accesibles.\n\n¿Qué te gustaría crear y para qué negocio?"));
    }

    private function onQualifying(Conversation $conversation, Lead $lead, Message $inbound, string $text): ?Message
    {
        // Detect the service over the whole burst ("Quiero una tienda" + "en línea" arrive split).
        $this->detectService($lead, $this->burstText($conversation, $inbound));
        $lead = $lead->fresh();

        // An explicit request OR any affirmative ("sí/va/dale") moves us to the scope —
        // never make the client type a magic keyword.
        if ($lead->service_id && ($this->wantsProposal($text) || $this->isYes($text))) {
            return $this->sendScope($conversation, $lead);
        }

        if (! $lead->service_id) {
            return $this->send($conversation, $this->claudeOr($conversation,
                'Con gusto 🙌 ¿Buscas una *página*, un *sitio web*, una *tienda en línea* o una *app*? Cuéntame un poco de tu negocio.'));
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            "¡Excelente! 🙌 Para preparar tu *alcance* a la medida cuéntame:\n"
            ."• ¿Qué quieres que *haga* exactamente? (los procesos o acciones clave)\n"
            ."• ¿Se *conecta con algo*? (cobrar pagos, WhatsApp, líneas/teléfono, o algún sistema/API/servicio que ya uses)\n"
            ."• ¿Algo *automático*? (que mande mensajes, agende, genere documentos, etc.)\n"
            ."• ¿*Quién* lo va a usar? (tú, tu equipo, tus clientes)\n"
            ."• ¿Ya tienes *logo, textos* o algún *ejemplo* de referencia?\n\n"
            .'O si ya lo tienes claro, dime *va* y te preparo el *alcance* detallado. ✅'));
    }

    private function onQuoted(Conversation $conversation, Lead $lead, string $text): ?Message
    {
        if ($this->isYes($text)) {
            return $this->sendBankDetails($conversation, $lead);
        }
        if ($this->looksLikePaymentProposal($text)) {
            return $this->recordPaymentProposal($conversation, $lead, $text);
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            'Quedo al pendiente 🙌 Cuando me confirmes que la aprobamos, te paso los datos para el anticipo del 40% y arrancamos.'));
    }

    private function onAwaitingPayment(Conversation $conversation, Lead $lead, bool $isMedia, string $text = ''): ?Message
    {
        if ($isMedia) {
            $lead->update(['stage' => LeadStage::AwaitingPayment]);

            // Only call it a "comprobante" when there's actually a deposit awaiting proof —
            // otherwise a logo/photo the client sends gets mis-acknowledged as a payment.
            $awaitingProof = $lead->paymentRequests()
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::ProofSubmitted->value])
                ->exists();
            if ($awaitingProof) {
                return $this->send($conversation,
                    '¡Recibí tu comprobante! 🙌 Verifico tu pago y, en cuanto quede aprobado, te creo tu *grupo de proyecto* y arrancamos. 🚀');
            }

            return $this->send($conversation, $this->claudeOr($conversation,
                '¡Gracias, lo recibí! 🙌 Si es tu *comprobante* del anticipo lo verifico enseguida; si es material para tu proyecto también lo guardo. ¿Me confirmas qué es?'));
        }

        if ($this->looksLikePaymentProposal($text)) {
            return $this->recordPaymentProposal($conversation, $lead, $text);
        }

        // Don't robot-repeat: engage with whatever the client says (questions, extra details,
        // requirements) and only gently remind about the deposit proof.
        return $this->send($conversation, $this->claudeOr($conversation,
            'Lo anoto 🙌 Cuando tengas listo el *comprobante* del anticipo (foto o PDF), mándamelo y arrancamos. Cualquier otro detalle, aquí estoy.'));
    }

    /** Public: owner approved/rejected a payment proposal → tell the client and continue. */
    public function resolveProposal(PaymentProposal $proposal, bool $approved, ?string $notes = null): void
    {
        $conversation = $proposal->conversation
            ?? Conversation::where('lead_id', $proposal->lead_id)->where('is_group', false)->first();
        if (! $conversation) {
            return;
        }
        $note = $notes ? "\n\n".$notes : '';
        if ($approved) {
            $msg = '¡Buenas noticias! 🎉 Revisamos tu propuesta de pago y *sí podemos manejarla así*.'.$note
                ."\n\nCuando gustes te paso los datos para hacer tu pago y arrancamos. 🙌";
        } else {
            $msg = '¡Gracias por tu paciencia! 🙏 Revisamos tu propuesta de pago y, por esta ocasión, no podríamos manejarla de esa forma.'.$note
                ."\n\nLo que sí podemos hacer es el esquema estándar (anticipo y el resto en pagos). ¿Le entramos así? 🙌";
        }
        $this->send($conversation, $msg);
    }

    /** Public: after payment, ask the client for everything we need to build (Overcloud-branded). */
    public function startGathering(Project $project): void
    {
        $conversation = Conversation::where('lead_id', $project->lead_id)->where('is_group', false)->first();
        if (! $conversation) {
            return;
        }
        $fallback = '¡Tu pago quedó verificado! ✅ Para construir tu proyecto necesito un par de cosas: el contenido y las fotos que quieras incluir, '
            .'los datos de tu negocio, y los accesos/llaves que apliquen (por ejemplo, para cobrar pagos en línea). '
            .'Si alguno no lo tienes o no sabes cómo sacarlo, *te guío paso a paso* — o si prefieres, *yo me encargo de todo*. ¿Cómo le hacemos? 🙌';
        $message = $fallback;
        if ($this->assistant->isEnabled()) {
            $spec = Spec::where('lead_id', $project->lead_id)->latest()->first();
            $feats = collect($spec?->content['features'] ?? [])->map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f)->filter()->implode(', ');
            $prompt = 'Eres el asistente de Overcloud, una agencia que construye sitios y aplicaciones. Acabamos de recibir el pago del cliente. '
                .'Escribe UN mensaje de WhatsApp, cálido y profesional, pidiéndole lo que necesitas para construir su proyecto: contenido y textos, fotos/logo y datos del negocio. '
                .'MUY IMPORTANTE: mira las funciones del proyecto e identifica qué *accesos o llaves* harán falta (por ejemplo: pasarela de pagos como Stripe o Mercado Pago, WhatsApp/Business, líneas o SIMs, Google Maps, correo, u otra API/servicio). Menciónaselos de forma concreta y, para los que probablemente no tenga, OFRÉCELE GUIARLO PASO A PASO para conseguirlos (qué cuenta abrir y dónde está la llave). '
                .'Ofrécele claramente 3 opciones: que te los pase, que lo guíes paso a paso, o que TÚ te encargas de todo por él. '
                .'NUNCA menciones herramientas internas ni proveedores de IA, ni plazos, fechas o tiempos de entrega. Habla como Overcloud. En español, breve, sin abrumar. '
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
        // WhatsApp bold is a SINGLE asterisk — collapse Markdown's ** (and ###/__) to WhatsApp style.
        $text = preg_replace('/\*{2,}/', '*', (string) $text);
        $text = preg_replace('/^#{1,6}\s*/m', '', (string) $text);
        $text = preg_replace('/__([^_]+)__/', '*$1*', (string) $text);

        return trim((string) $text, " \t\n\r\"");
    }

    /** Gathering: collect what the client shares; build when they say go / "do it all". */
    private function onGathering(Conversation $conversation, Lead $lead, Message $inbound, string $text): ?Message
    {
        $project = Project::where('lead_id', $lead->id)->latest()->first();
        if (! $project) {
            return $this->send($conversation, 'Dame un momentito, estoy preparando todo para arrancar. 🙌');
        }

        // Read the whole burst (the debounce replies once to the latest fragment, but a key/value
        // can be split across messages — "aquí va mi llave" then "sk_live_…") so nothing is lost.
        $burst = $this->burstText($conversation, $inbound);

        $brief = (array) ($project->brief ?? []);
        if (filled($inbound->body)) {
            $brief['requirements'][] = $inbound->body;

            // Capture any API keys/credentials the client shares — injected at deploy time.
            if ($this->looksLikeSecret($burst)) {
                $env = $this->extractSecrets($burst);
                if ($env) {
                    $brief['env'] = array_merge((array) ($brief['env'] ?? []), $env);
                    $project->update(['brief' => $brief]);

                    return $this->send($conversation, '¡Recibí tus accesos y los guardé de forma segura! 🔐 ¿Hay algo más o *arrancamos*?');
                }
            }
        }

        $all = $this->wantsAll($text);
        // Only START the real build on a clear go-signal — and NOT while the client is still
        // saying they'll send material ("te paso las fotos", "ahorita te mando"), which used to
        // kick off a premature deploy with an incomplete brief (and lose the keys they were about to send).
        if (($all || $this->readyToBuild($text) || $this->isYes($text)) && ! $this->promisingToSend($text)) {
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
            '¡Gracias, lo anoto! 🙌 ¿Hay algo más que quieras incluir o *arrancamos*? Si necesitas alguna llave o acceso (por ejemplo para cobrar pagos) y no sabes cómo sacarlo, te guío paso a paso — o me encargo yo de todo. Solo dime.',
            $this->gatheringPersona()));
    }

    /** After delivery: apply change requests, otherwise stay available. */
    private function onProduction(Conversation $conversation, Lead $lead, Message $inbound, string $text): ?Message
    {
        $project = Project::where('lead_id', $lead->id)->latest('id')->first();
        if ($project && $project->repo_url && $project->coolify_app_uuid && $this->looksLikeChange($text)) {
            // Send Claude the ORIGINAL-case message, never the lowercased match copy
            // (lowercasing corrupts URLs, brand casing and filenames in the instruction).
            $instruction = trim((string) $inbound->body);

            // Voice notes are transcribed (often imperfectly). NEVER mutate a live site from an
            // unconfirmed transcription — echo what we understood and wait for an explicit "sí".
            if ($inbound->type === MessageType::Audio) {
                $conversation->setPendingChange($instruction);

                return $this->send($conversation,
                    "Entendí que quieres este cambio:\n«{$instruction}»\n\n¿Lo aplico a tu sitio? Respóndeme *sí* para confirmar. 🙌");
            }

            return $this->dispatchConfirmedChange($conversation, $lead, $instruction);
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            'Tu proyecto ya está en línea ✅ Si quieres algún ajuste o cambio, descríbemelo y lo aplico. 🙌'));
    }

    /** Apply a confirmed live-site change: dispatch the job and acknowledge the client. */
    private function dispatchConfirmedChange(Conversation $conversation, Lead $lead, string $instruction): ?Message
    {
        $conversation->clearPendingChange();
        $project = Project::where('lead_id', $lead->id)->latest('id')->first();
        if (! $project || ! $project->repo_url || ! $project->coolify_app_uuid) {
            return $this->send($conversation, $this->claudeOr($conversation,
                'Tu proyecto ya está en línea ✅ Si quieres algún ajuste o cambio, descríbemelo y lo aplico. 🙌'));
        }

        ApplyChange::dispatch($project->id, $instruction)->onQueue('deploy');

        // Give the client a live progress link for the change, just like an initial build.
        $deploy = app(DeployService::class);
        $deploy->reportChangeProgress($project, 0);

        return $this->send($conversation, '¡Claro! 🙌 Aplico ese cambio en tu sitio y te aviso en cuanto quede actualizado. 🔧'
            ."\n\n📺 Puedes ver el avance en vivo aquí:\n".$deploy->progressUrl($project));
    }

    /** Scope stage: wait for the client to confirm the alcance before quoting. */
    private function onScope(Conversation $conversation, Lead $lead, string $text): ?Message
    {
        if ($this->isYes($text)) {
            return $this->startDemo($conversation, $lead);
        }

        // Persist the requested adjustment onto the spec so the demo + quote actually reflect it
        // (it used to be acknowledged and then lost, leaving the quote built from the stale scope).
        $this->captureFeedback($lead, $text);

        return $this->send($conversation, $this->claudeOr($conversation,
            'Con gusto ajusto lo que necesites del alcance 🙌 ¿Qué te gustaría cambiar o agregar? En cuanto me confirmes que está a tu gusto, te preparo un *demo* visual.'));
    }

    /** Before quoting: build + deploy a quick visual demo for the client to fall in love with. */
    private function startDemo(Conversation $conversation, Lead $lead): ?Message
    {
        $lead->update(['stage' => LeadStage::Negotiating]);
        if (! config('overcloud.deploy.enabled')) {
            return $this->send($conversation, '¡Perfecto! 🙌 Te preparo tu sistema y te aviso aquí en un momento.');
        }

        // The demo IS the full REAL system, delivered as a 5-day TRIAL with the selling/revenue features
        // locked until the anticipo. Build it through the real pipeline (branding + Postgres + verify+heal).
        $project = $this->ensureDemoProject($lead);
        DeployProject::dispatch($project->id)->onQueue('deploy');

        return $this->send($conversation,
            '¡Perfecto! 🙌 Te voy a armar tu *sistema completo* — real y funcional — para que lo veas y lo pruebes en vivo. '
            ."Podrás explorarlo TODO; solo lo de *cobrar y vender* se activa con tu anticipo. Tarda unos minutos.\n\n"
            ."📺 Mira el avance en vivo aquí:\n".app(DeployService::class)->progressUrl($project));
    }

    /** The lead's current demo/trial Project (the full build), creating it on first demo. */
    private function ensureDemoProject(Lead $lead): Project
    {
        $project = Project::where('lead_id', $lead->id)
            ->whereNull('delivered_at')
            ->where('status', '!=', ProjectStatus::Cancelled->value)
            ->latest('id')->first();
        if ($project) {
            return $project;
        }
        $accountId = $lead->conversations()->whereNotNull('whatsapp_account_id')->value('whatsapp_account_id');

        return Project::create([
            'lead_id' => $lead->id,
            'whatsapp_account_id' => $accountId,
            'name' => ($lead->service?->name ?? 'Proyecto').' · '.($lead->name ?? 'Cliente'),
            'slug' => Str::slug(($lead->name ?? 'proyecto').'-'.Str::lower(Str::random(5))),
            'type' => $lead->service?->key,
            'status' => ProjectStatus::Queued,
            'started_at' => now(),
            'brief' => ['demo' => true, 'trial' => true],
        ]);
    }

    /**
     * Client gave demo feedback ("colores neutros", "estilo Expedia"…). If the demo is already live,
     * apply it as a change (rebuild + redeploy, with its own progress link); if it's still building,
     * captureFeedback already stored it and the in-flight build will include it.
     */
    private function rebuildDemoWithFeedback(Lead $lead, string $feedback): void
    {
        if (! config('overcloud.deploy.enabled')) {
            return;
        }
        $project = Project::where('lead_id', $lead->id)
            ->whereNull('delivered_at')
            ->where('status', '!=', ProjectStatus::Cancelled->value)
            ->latest('id')->first();
        if ($project && $project->repo_url && $project->coolify_app_uuid) {
            ApplyChange::dispatch($project->id, $feedback)->onQueue('deploy');
        }
    }

    /** Demo stage: when the client loves the demo, send the quote. */
    private function onDemo(Conversation $conversation, Lead $lead, string $text): ?Message
    {
        if ($this->isYes($text)) {
            return $this->sendQuote($conversation, $lead);
        }

        // Capture the requested change and, when it's a concrete adjustment, REBUILD the demo so it
        // actually shows up — instead of only acknowledging it (the old behavior lost the change).
        $this->captureFeedback($lead, $text);
        if ($this->mentionsAdjustment($text)) {
            $this->rebuildDemoWithFeedback($lead, $text);
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            '¡Qué bueno que te gusta! 🙌 Cuéntame qué le falta o qué información de tu negocio quieres que agregue, y lo dejo a tu medida en el demo. 🎨'));
    }

    /** Persist a client's scope/demo change request onto the latest spec so downstream builds + the
     *  quote reflect it (kept bounded so it can't grow without limit). */
    private function captureFeedback(Lead $lead, string $text): void
    {
        $text = trim($text);
        if ($text === '' || ! $this->mentionsAdjustment($text)) {
            return;
        }
        $spec = $lead->specs()->latest()->first();
        if (! $spec) {
            return;
        }
        $content = (array) ($spec->content ?? []);
        $content['feedback'] = array_values(array_slice(
            array_merge((array) ($content['feedback'] ?? []), [$text]), -20
        ));
        $spec->content = $content;
        $spec->save();
    }

    /** The message describes a concrete change/addition to the scope or demo (not just chit-chat). */
    private function mentionsAdjustment(string $text): bool
    {
        return Str::contains($text, [
            'agreg', 'añad', 'añád', 'cambi', 'cámbi', 'pon', 'quita', 'incluy', 'inclúy', 'súma', 'suma',
            'falta', 'me gustaría', 'me gustaria', 'quiero que', 'quisiera', 'color', 'logo', 'foto', 'imagen',
            'sección', 'seccion', 'página', 'pagina', 'menú', 'menu', 'horario', 'dirección', 'direccion',
            'teléfono', 'telefono', 'whatsapp', 'mapa', 'precio', 'producto',
        ]);
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

    /**
     * Build + send the quote standalone — used to send it RIGHT when the first demo version is delivered,
     * so the client sees the complete (locked) system AND its price + anticipo together.
     */
    public function sendQuoteForLead(Lead $lead): void
    {
        $conv = $lead->conversations()->where('is_group', false)->first();
        if (! $conv) {
            return;
        }
        try {
            $spec = $lead->specs()->latest()->first() ?? $this->specs->buildFromLead($lead);
            $quote = $this->quotes->buildFromLead($lead, $spec, [
                'feature_ids' => $this->defaultFeatures($lead->service),
                'pages' => $lead->pages ?: ($lead->service?->included_pages ?? 1),
                'languages' => max(1, count($lead->languages ?? ['es'])),
            ]);
            $this->pdf->renderQuote($quote);
            $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);
            $dep = Money::format($quote->deposit_cents, $quote->currency);
            $this->send($conv,
                "💰 Y aquí tu *cotización* (PDF *{$quote->number}*) — el detalle completo y el plan de pagos.\n\n"
                ."Para *activar todo* (cobrar y vender) y dejar tu sistema *fijo para siempre*, aparta tu proyecto con el *anticipo del 40%*: {$dep}. Cuando quieras, dime *va* y te paso los datos de pago. ✅");
            $this->sendDoc($conv, $quote->fresh()->pdf_path, $quote->number.'.pdf');
        } catch (\Throwable $e) {
            Log::warning('sendQuoteForLead failed', ['lead' => $lead->id, 'e' => $e->getMessage()]);
        }
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
        if (! empty($snap['instructions'])) {
            $msg .= $snap['instructions']."\n";
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
        // A qualified or concerned reply ("me gusta pero te falta…") is NOT a clean approval.
        if ($this->hasReservation($text)) {
            return false;
        }

        // An explicit decline or "not yet" is never a yes ("no apruebo", "todavía no", "mejor no").
        if (preg_match('/\bno\s+(lo\s+|le\s+|me\s+)?(apruebo|aprueb|acepto|acept|quiero|gracias|por ahora|todav|aun|aún)/u', $text)
            || preg_match('/\b(todav[ií]a no|a[uú]n no|por ahora no|no por ahora|ahorita no|mejor no|aún no|aun no)\b/u', $text)) {
            return false;
        }

        return Str::contains($text, ['aprob', 'aprueb', 'acept', 'adelante', 'dale', 'sí', 'si,', 'claro', 'ok', 'okay', 'perfecto', 'me late', 'encant', 'me gusta', 'me fascina', 'genial', 'excelente', 'procede', 'proced', 'de acuerdo', 'va pues', 'hágale', 'hagale', 'confirm']);
    }

    /** The client wants a real person / doesn't want to deal with a bot → hand off to a human. */
    private function wantsHuman(string $text): bool
    {
        $text = ' '.trim($text).' ';

        return (bool) preg_match('/(asesor real|un asesor|una asesora|atenci[oó]n humana|'
            .'(hablar|hablo|comunicar|comunicarme|pasar|pasas?|p[aá]same|contactar|conectar|con[eé]ctame) con (un|una|alg[uú]ien|el|la)?\s*(humano|persona|asesor|asesora|agente|ejecutiv|alguien real|alguien|due[ñn]o|encargad)|'
            .'quiero (un|una|hablar con|que me atienda un|que me atienda una)?\s*(humano|persona|asesor|asesora|agente|alguien real)|'
            .'prefiero (un|una|hablar con|que me atienda)?\s*(humano|persona|asesor|asesora|agente|alguien)|'
            .'no quiero (hablar con)?\s*(un|una)?\s*(bot|ia|robot|inteligencia artificial|asistente)|'
            .'hablar con (un|una)?\s*(ia|bot|robot|inteligencia artificial)\s*no|'
            .'no me (interes|gust)\w*\s*(hablar con\s*(un|una)?\s*)?(ia|bot|robot|asistente|inteligencia artificial))/u', $text);
    }

    /** Hand the client off to the OWNER: notify the owner, give the client the owner's WhatsApp, and
     *  pause the bot so a human takes over. Never marks Lost, never deletes anything. */
    private function handoffToOwner(Conversation $conversation, Lead $lead, string $reason): ?Message
    {
        $owner = (string) config('overcloud.company.owner_phone');
        $who = $lead->company ?: ($lead->name ?: ($conversation->contact_phone ?? 'cliente'));
        $meta = (array) ($conversation->meta ?? []);
        $meta['handoff_to_human_at'] = now()->toIso8601String();
        $meta['handoff_reason'] = Str::limit($reason, 160);
        $conversation->meta = $meta;
        $conversation->status = ConversationStatus::Human; // panel shows it needs a human
        $conversation->ai_enabled = false;                  // pause the bot so the human takes over
        $conversation->save();

        // Alert the owner (best-effort, throttled once per hour per lead).
        try {
            if ($owner && $conversation->whatsappAccount
                && Cache::add('handoff-alert:'.$lead->id, 1, now()->addHour())) {
                $last = $conversation->messages()->where('is_from_me', false)->latest('id')->value('body');
                $this->gateway->sendText($conversation->whatsappAccount->session_name, $owner.'@s.whatsapp.net',
                    "👤 *Un cliente pidió un asesor* ({$who}). Toma la conversación — pausé el bot.\n\n"
                    .'Tel: '.($conversation->contact_phone ?: 's/d')."\n💬 \"".Str::limit((string) $last, 100).'"');
            }
        } catch (\Throwable $e) {
        }

        $link = $owner ? 'https://wa.me/'.preg_replace('/\D/', '', $owner) : null;

        return $this->send($conversation,
            '¡Claro que sí! 🙌 Te paso con uno de nuestros asesores para que te atienda personalmente.'
            .($link ? "\n\nEscríbele aquí directamente: {$link}" : '')
            ."\nEn breve también te contactan. ¡Quedo al pendiente! 😊");
    }

    /** Clear sign the client lost interest / declined for good (not a passing concern or a "no" mid-detail). */
    private function looksLost(string $text): bool
    {
        $text = ' '.trim($text).' ';

        return (bool) preg_match('/\b(no me interes|ya no me interes|no estoy interesad|no me convenci|no es lo que (busco|necesito)|'
            .'mejor (lo dejamos|déjalo|dejalo)|ya no (quiero|me interesa)|olv[ií]da|no gracias|paso gracias)/u', $text);
    }

    /** Gracefully close a lost lead: thank + leave the door open, mark Lost, free the demo, pause —
     *  but KEEP the lead + conversation as a record in the panel (with the reason). */
    private function closeLost(Conversation $conversation, Lead $lead, string $reason): ?Message
    {
        $lead->update(['stage' => LeadStage::Lost]);
        $meta = (array) ($conversation->meta ?? []);
        $meta['lost_reason'] = Str::limit('Cliente no interesado: '.$reason, 180);
        $meta['lost_at'] = now()->toIso8601String();
        $conversation->meta = $meta;
        $conversation->ai_enabled = false; // stop the bot, but keep the thread as a record
        $conversation->save();

        // Free the client's demo resources (Coolify app + DNS) in the background — keep the DB record.
        RemoveDemo::dispatch($lead->id)->onQueue('deploy');

        return $this->send($conversation,
            '¡Gracias por tu tiempo! 🙏 Quedo a la orden por si más adelante cambias de opinión — aquí estaré con gusto. ¡Te deseo mucho éxito! 🙌');
    }

    /** True when the client likes it BUT wants changes/more info — not a clean yes. */
    private function hasReservation(string $text): bool
    {
        if (preg_match('/\b(pero|aunque|sin embargo|excepto|salvo que|nada más que)\b/u', $text)) {
            return true;
        }

        return Str::contains($text, [
            'te falta', 'le falta', 'me falta', 'hace falta', 'falta info', 'faltó', 'faltan', 'falta agregar',
            'agreg', 'añad', 'cambi', 'cámbi', 'modific', 'quítale', 'quitale', 'quisiera que', 'me gustaría que',
            'podrías', 'puedes agregar', 'puedes poner', 'puedes cambiar', 'antes quiero', 'antes de pagar',
            'antes de avanzar', 'antes de seguir', 'antes de continuar', 'antes de aprobar', 'antes de arrancar',
            'una duda', 'no me gust',
        ]);
    }

    /** Client wants us to handle everything ("hazlo tú", "encárgate de todo"). */
    private function wantsAll(string $text): bool
    {
        // NB: no bare 'de todo' — it matched "te paso fotos de todo el negocio" and triggered a build.
        return Str::contains($text, ['hazlo tú', 'hazlo tu', 'hazlo todo', 'haz todo', 'encárgate de todo', 'encargate de todo', 'encárgate tú', 'tú te encargas', 'tu te encargas', 'tú hazlo', 'tu hazlo', 'has todo', 'hazlo por mí', 'hazlo por mi']);
    }

    /** An explicit "build it now" go-signal (distinct from a polite "ok"/"perfecto"). */
    private function readyToBuild(string $text): bool
    {
        return Str::contains($text, ['arranca', 'arráncalo', 'arrancalo', 'empieza ya', 'empiézalo', 'empiezalo', 'comiénzalo', 'comienzalo', 'constrúyelo', 'construyelo', 'constrúyela', 'construyela', 'hazlo ya', 'ya está todo', 'ya quedó todo', 'manos a la obra', 'a darle']);
    }

    /** The client is still about to SEND material ("te paso las fotos", "ahorita te mando") — don't
     *  start the build yet or we ship with an incomplete brief / lose the keys they're sending. */
    private function promisingToSend(string $text): bool
    {
        return (bool) preg_match('/\b(te\s+(mando|paso|env[ií]o|comparto|manda)|voy\s+a\s+(mandar|enviar|pasar|subir)|ahorita\s+te|al\s+rato\s+te|en\s+un\s+momento\s+te|d[eé]jame\s+(busc|junt|prepar|reun)|estoy\s+(juntando|reuniendo|preparando)|junto\s+(la|los|las))/u', $text);
    }

    /** Concatenate the client's current burst (messages since our last reply) so per-message
     *  extractors (service detection, secret capture) see fragments that were split across bubbles. */
    private function burstText(Conversation $conversation, Message $inbound): string
    {
        $lastOut = $conversation->messages()->where('is_from_me', true)->latest()->first();
        $q = $conversation->messages()->where('is_from_me', false);
        if ($lastOut) {
            $q->where('id', '>', $lastOut->id);
        }
        $bodies = $q->orderBy('id')->limit(10)->pluck('body')->filter()->all();
        if (filled($inbound->body) && ! in_array($inbound->body, $bodies, true)) {
            $bodies[] = $inbound->body;
        }

        return trim(implode("\n", $bodies));
    }

    /** Heuristic: the client is describing a change to the delivered site (a command, not a
     *  question or a "don't change anything"). Biased to false on ambiguity — a missed change
     *  just re-prompts the client, while a false positive would auto-deploy on their live site. */
    private function looksLikeChange(string $text): bool
    {
        $text = trim($text);
        // A question is not a change order ("¿se puede cambiar el color?", "cuánto cuesta el logo").
        if (Str::endsWith($text, '?') || Str::startsWith($text, ['¿', 'que ', 'qué ', 'como ', 'cómo ', 'cuando ', 'cuándo ', 'cuanto ', 'cuánto ', 'donde ', 'dónde ', 'por que', 'por qué', 'puedo ', 'se puede', 'podrias', 'podrías', 'podemos'])) {
            return false;
        }
        // An explicit negation is not a change order ("no le cambies nada al texto").
        if (preg_match('/\bno\s+(me\s+|le\s+|lo\s+|la\s+)?(cambies|cambie|agregues|agregue|quites|quite|pongas|ponga|modifiques|modifique|toques|toque|muevas|actualices)\b/u', $text)) {
            return false;
        }

        // Require an actual change ACTION (a verb or a size modifier) — a bare noun like
        // "color"/"foto"/"logo"/"precio" alone is usually a question, a compliment or a fact,
        // and must not auto-deploy. The client describing a real edit always uses a verb.
        return Str::contains($text, [
            'cambi', 'cámbi', 'agrega', 'agrég', 'añade', 'añád', 'anade', 'quita', 'quíta',
            'pon ', 'ponle', 'ponme', 'modific', 'ajusta', 'ajús', 'mueve', 'muéve', 'actualiza',
            'reemplaza', 'súbele', 'subele', 'bájale', 'bajale', 'corrige', 'corríge', 'arregla',
            'coloca', 'elimina', 'borra', 'más grande', 'mas grande', 'más chico', 'mas chico',
            'más pequeñ', 'mas pequeñ',
        ]);
    }

    /** Client proposing a different payment arrangement than the standard plan. */
    private function looksLikePaymentProposal(string $text): bool
    {
        return Str::contains($text, [
            'pago único', 'pago unico', 'sin mensualidad', 'sin pagar mensualidad', 'una sola exhibición', 'una exhibicion',
            'puedo pagar', 'podría pagar', 'pagar todo', 'en vez de pagar', 'otra forma de pago', 'descuento', 'rebaja',
            'a meses', 'en partes diferente', 'mejor precio', 'precio especial', 'no me alcanza', 'más barato', 'mas barato',
        ]);
    }

    /** Record an alternative-payment proposal for the owner to approve/reject, and acknowledge. */
    private function recordPaymentProposal(Conversation $conversation, Lead $lead, string $text): Message
    {
        // One pending proposal per lead — update its text instead of piling up duplicates.
        $existing = PaymentProposal::where('lead_id', $lead->id)->where('status', 'pending')->first();
        if ($existing) {
            $existing->update(['proposal' => $text, 'conversation_id' => $conversation->id]);
        } else {
            PaymentProposal::create([
                'lead_id' => $lead->id, 'conversation_id' => $conversation->id,
                'proposal' => $text, 'status' => 'pending',
            ]);
        }
        // Alert the owner at most once per hour per lead (don't spam them).
        try {
            $owner = (string) config('overcloud.owner_phone');
            if ($owner && $conversation->whatsappAccount
                && Cache::add('proposal-alert:'.$lead->id, 1, now()->addHour())) {
                $who = $lead->company ?: ($lead->name ?: $conversation->contact_phone);
                $this->gateway->sendText($conversation->whatsappAccount->session_name, $owner.'@s.whatsapp.net',
                    "💳 *Propuesta de pago* de {$who}:\n\"".Str::limit($text, 120)."\"\n\nApruébala o recházala en el panel y el bot le avisa.");
            }
        } catch (\Throwable $e) {
        }

        return $this->send($conversation, $this->claudeOr($conversation,
            '¡Gracias por proponerlo! 🙌 Déjame revisarlo con el equipo y te confirmo en breve si lo podemos manejar así. Lo estamos considerando. 👍'));
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
            Str::contains($text, ['app', 'sistema', 'plataforma', 'panel', 'dashboard', 'saas', 'crm', 'erp', 'software', 'gestion', 'gestión', 'management', 'system', 'platform', 'portal', 'booking', 'reservas', 'marketplace', 'inventario']) => 'webapp',
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
    private function claudeOr(Conversation $conversation, string $fallback, ?string $persona = null): string
    {
        if (! $this->assistant->isEnabled()) {
            return $fallback;
        }
        try {
            return $this->composeWithClaude($conversation, $persona) ?: $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function composeWithClaude(Conversation $conversation, ?string $persona = null): ?string
    {
        $reply = $this->assistant->message($persona ?: $this->salesPersona(), $this->history($conversation));

        return $reply ? $this->cleanMessage($reply) : null;
    }

    /**
     * Post-payment onboarding persona: collects what's needed to build AND actively HELPS the client
     * obtain the integration credentials their project needs (step-by-step, non-technical) — e.g. opening
     * a Stripe/Mercado Pago account and where to copy the key. Only ever used AFTER the client has paid.
     */
    private function gatheringPersona(): string
    {
        return 'Eres el asistente de Overcloud que pone EN MARCHA el proyecto de un cliente que YA pagó. Hablas español, cálido, claro y sin tecnicismos. '
            .'Tu trabajo ahora: reunir lo necesario para construir (contenido, textos, fotos, logo, datos del negocio) y, sobre todo, AYUDARLE a conseguir y conectar los *accesos/llaves* que requiera su proyecto (por ejemplo: pasarela de pagos como Stripe o Mercado Pago, WhatsApp/Business, líneas o SIMs, Google Maps, correos, o cualquier API/servicio que se haya definido). '
            .'Si necesita una credencial que no tiene, GUÍALO PASO A PASO de forma sencilla: qué cuenta abrir, en qué página, dónde encontrar la llave y cómo pasártela — un paso a la vez, sin abrumar, y ofreciendo hacerlo por él cuando se pueda. '
            .'Cuando te comparta una llave o acceso, confírmale que lo guardaste de forma segura. Siempre ofrece tres caminos: que te lo pase, que lo guíes paso a paso, o que TÚ te encargues de todo por él. '
            .'Cuando ya tengan lo esencial, anímalo a que *arranques* la construcción. NUNCA pidas plazos ni des fechas, NUNCA menciones errores ni nada técnico negativo, y NUNCA reveles herramientas internas ni proveedores de IA. '
            .$this->aiSecrecyRule();
    }

    /** The shared Overcloud sales persona + rules — used for conversational wording AND AI routing. */
    private function salesPersona(): string
    {
        return 'Eres el asistente de ventas de Overcloud, una agencia que crea páginas, sitios web, tiendas en línea y apps a precios accesibles. '
            .'Hablas español, cálido, breve y profesional. Tu objetivo: entender qué necesita el cliente (tipo de proyecto, páginas, idiomas, si tiene logo/textos o sitios de referencia) para prepararle su proyecto. '
            .'FLUJO en este orden, explícalo si ayuda: 1) le preparas un *alcance* detallado (un documento, sin costo) con todo lo que incluye; 2) le armas un *demo visual* en vivo para que lo vea; 3) hasta el final, la *cotización*. '
            .'Cuando el cliente confirme que quiere avanzar (un "sí", "va", "dale"…), NO le pidas que escriba ninguna palabra clave: simplemente dile que le preparas su *alcance* enseguida. Nunca presentes "cotización" como primer paso ni le pidas teclear palabras. '
            .'Cuando ya tengas lo necesario, NO pidas otra confirmación tipo "¿Va?" o "¿te parece?": di directamente que estás preparando su *alcance* AHORA y déjalo así. Nada de pedir permiso para cada paso. '
            .'REGLAS ESTRICTAS: NUNCA generes ni escribas tú una cotización, propuesta, lista de opciones, paquetes ni precios — el *alcance* y la *cotización* son DOCUMENTOS (PDF) que el sistema genera automáticamente; tú solo conversas y guías. '
            .'NUNCA digas que "un asesor te contactará" ni delegues en un humano: tú llevas todo el proceso de principio a fin. '
            .'ANTES de preparar el *alcance*, entiende BIEN el proyecto — no solo el tipo. Pregunta por lo que de verdad cambia lo que hay que construir: qué *procesos o acciones* debe hacer, con qué se *conecta* (cobros/pagos, WhatsApp, líneas o teléfono, algún sistema/API/servicio que ya use), qué debe ser *automático* (mandar mensajes, agendar, generar documentos…), *quién* lo usará (él, su equipo, sus clientes) y qué *datos* maneja. Haz 2-3 preguntas agrupadas y concretas (no de una en una), y profundiza en lo CLAVE de su caso: si quiere un bot/automatización o integraciones, pregunta EXACTAMENTE qué debe automatizar y con qué se integra (p. ej. para líneas/SIMs/APIs: cuáles, de qué proveedor). En un proyecto simple (una página informativa) NO interrogues de más. Cuando ya tengas claro QUÉ hace y CON QUÉ se conecta, di que le preparas su *alcance*. No inventes precios. '
            .'Si el cliente propone otra forma de pago o alguna condición distinta (no le acomodan los pagos en partes, quiere otro esquema, etc.), NO la rechaces: dile que lo revisas con el equipo y le confirmas en breve si se puede — que ya lo están considerando. '
            .'Si el cliente comparte un enlace de referencia (su sitio actual, un ejemplo, etc.), agradécelo y dile que lo revisarás a fondo para tomarlo de base. '
            .'Si pregunta por tiempos de entrega: la entrega es muy rápida, el proyecto queda listo en 1 día o menos, y los cambios o correcciones se hacen cuando el cliente los pida. Nunca menciones plazos de semanas. '
            .'PROHIBIDO ABSOLUTO: NUNCA menciones errores, fallas, "problema de servidor", que algo "no cargó", "se cayó", "falló" ni nada técnico negativo. Si un enlace no abrió, solo di con naturalidad que en un momento le compartes el enlace, sin explicar por qué. Mantén SIEMPRE un tono positivo y profesional. '
            .'NUNCA inventes ni ofrezcas descuentos, promociones, precios ni condiciones por tu cuenta. '
            .'RESPONDE SIEMPRE sus dudas (no las esquives ni repitas lo mismo). Sobre la *mensualidad*: INCLUYE el *hosting* (lo que mantiene el sitio/app EN LÍNEA) más cambios y soporte continuos. Si NO se paga, el sitio deja de estar en línea. Hay dos planes: *solo hosting* (más económico, solo lo mantiene online) y *hosting + soporte* (también cambios y mejoras cuando los pida). El plan de *solo hosting* ofrécelo ÚNICAMENTE si el cliente lo pide. El *proyecto* es un pago único aparte de la mensualidad. '
            .'Si pide funciones extra (ej. un botón para hablar directo con él, un menú de servicios, etc.), dile que con gusto se incluyen y las anotas. Sé concreto y cálido; jamás repitas la misma frase dos veces. '
            .$this->aiSecrecyRule();
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
        // Anti-spam guard: don't send the SAME message twice in a row — but ONLY if the client
        // hasn't written since. A new client message deserves a reply even if (e.g. with Claude
        // down) it maps to the same deterministic fallback; otherwise the client gets total silence.
        $lastBot = $conversation->messages()->where('is_from_me', true)->latest()->first();
        if ($lastBot && trim((string) $lastBot->body) === trim($text)) {
            $clientWroteSince = $conversation->messages()->where('is_from_me', false)
                ->where('id', '>', $lastBot->id)->exists();
            if (! $clientWroteSince) {
                return $lastBot;
            }
        }

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
