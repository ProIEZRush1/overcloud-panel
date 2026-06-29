<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Milestone + maintenance billing: after deploy, charge the 30% (7-day window + changes),
 * then the final 30% (7 days), then monthly maintenance starting next month — all with
 * WhatsApp reminders and pausing the site when overdue. Synced to Dev-Business.
 */
class BillingService
{
    private const GRACE_DAYS = 7;

    public function __construct(
        private PaymentService $payments,
        private WhatsAppGateway $gateway,
        private CrmSync $crm,
    ) {}

    /** Called when a project goes live: hand over the site + admin + charge the 30% milestone. */
    public function onDeployed(Project $project): void
    {
        $project->loadMissing('lead', 'quote');
        $project->update(['ready_at' => $project->ready_at ?? now()]);
        [$conv, $account] = $this->channel($project);
        if (! $conv || ! $account) {
            return;
        }

        // Comped projects (e.g. a courtesy build) deliver the live site WITHOUT any charge or dunning.
        $comped = (bool) ($project->brief['comped'] ?? false);

        $quote = $project->quote;
        $pr = null;
        if ($quote && ! $comped) {
            $deploy = (int) round($quote->total_cents * 0.30);
            $pr = $this->payments->createBalance($quote, $project, 'Hito despliegue (30%) · '.$quote->number, $deploy, self::GRACE_DAYS);
        }

        $admin = (array) (($project->brief['admin'] ?? []));
        $isBot = (bool) ($project->brief['bot'] ?? false);
        // A WhatsApp-bot product is delivered as a TRIAL: the client opens the panel, scans the QR to
        // link their OWN WhatsApp, and the bot starts selling. (You can't demo a bot as a static URL.)
        if ($isBot) {
            $connect = $project->brief['connect_url'] ?? (rtrim((string) $project->prod_url, '/').'/conectar');
            $msg = '¡Tu *bot de WhatsApp* ya está listo, '.($project->lead?->name ?: '').'! 🤖🚀'."\n\n"
                ."🌐 *Tu panel:* {$project->prod_url}\n";
            if (! empty($admin['email'])) {
                $msg .= '🔐 Acceso: '.$admin['email'].' / '.($admin['password'] ?? '')."\n";
            }
            $msg .= "\n📲 *Para activarlo (pruébalo en vivo):*\n"
                ."1) Entra a *Conectar WhatsApp*: {$connect}\n"
                ."2) En tu WhatsApp: *Dispositivos vinculados → Vincular un dispositivo* y escanea el QR\n"
                ."3) ¡Listo! Tu bot empieza a atender y vender solo. Escríbele a tu número para probarlo. 🎉\n\n"
                .'🔧 Cualquier ajuste al flujo o a tu catálogo, pídemelo por aquí y lo aplico.';
            $this->gateway->sendText($account->session_name, $conv->contact_jid, $msg);

            return;
        }
        $msg = "¡Tu proyecto ya está en línea! 🚀\n🌐 *Tu sistema:* {$project->prod_url}\n";
        if (! empty($admin['email']) || ! empty($admin['user'])) {
            $loginUrl = $admin['url'] ?? (rtrim((string) $project->prod_url, '/').'/login');
            $msg .= "🔐 *Acceso a tu panel de administración:*\n   {$loginUrl}\n"
                .'   Usuario: '.($admin['email'] ?? $admin['user']).' / Contraseña: '.($admin['password'] ?? $admin['pass'] ?? '')."\n";
        }
        // Always explain access + that the GROUP is the channel to customize and change the system.
        $msg .= "\n📲 Solo abre el enlace en cualquier navegador y ya es tu sistema funcionando.\n";
        $msg .= "🔧 Para *cambios, personalizaciones o nuevas funciones*, escríbeme en tu *grupo de proyecto* — justamente sirve para eso, mantenerlo y mejorarlo. Te paso un *documento con todos los detalles* de tu sistema. 📄\n";
        if ($comped) {
            $msg .= "\n💚 Este proyecto es una *cortesía*, totalmente sin costo. Disfrútalo y cuenta conmigo para lo que necesites.";
        } elseif ($pr) {
            $msg .= "\nPara continuar, el siguiente pago es el *30% del avance*: ".Money::format($pr->amount_cents, $pr->currency)."\n"
                .$this->bankLines($pr)
                ."\n⏳ Tienes *7 días* para realizarlo y enviar tus cambios. Después de ese plazo pausamos el sitio hasta recibir el pago. 🙏";
        }
        $this->gateway->sendText($account->session_name, $conv->contact_jid, $msg);

        // Attach the "Detalles de tu sistema" PDF (URL, qué incluye, cómo acceder y pedir cambios).
        try {
            $path = app(PdfService::class)->renderDelivery($project);
            if (Storage::exists($path)) {
                $this->gateway->sendMedia($account->session_name, $conv->contact_jid, [
                    'base64' => base64_encode(Storage::get($path)),
                    'mimetype' => 'application/pdf',
                    'fileName' => 'Detalles de tu sistema.pdf',
                    'kind' => 'document',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('delivery PDF failed', ['project' => $project->id, 'e' => $e->getMessage()]);
        }

        $this->sync($pr);
    }

    /** Called from payment verification for a balance/maintenance payment. */
    public function onPaymentVerified(PaymentRequest $request): void
    {
        if (! in_array($request->type, [PaymentType::Balance, PaymentType::Maintenance], true)) {
            return;
        }
        $project = $request->project ?? Project::where('lead_id', $request->lead_id)->latest()->first();
        if ($project && $project->paused_at) {
            $this->resumeProject($project);
        }
        [$conv, $account] = $project ? $this->channel($project) : [null, null];
        $ref = Str::lower((string) $request->reference);

        if (Str::contains($ref, 'despliegue') && $project?->quote) {
            // Deploy 30% paid → bill the final 30%.
            $quote = $project->quote;
            $deploy = (int) round($quote->total_cents * 0.30);
            $final = max(0, $quote->total_cents - $quote->deposit_cents - $deploy);
            $pr = $this->payments->createBalance($quote, $project, 'Hito final (30%) · '.$quote->number, $final, self::GRACE_DAYS);
            if ($conv && $account) {
                $this->gateway->sendText($account->session_name, $conv->contact_jid,
                    '¡Gracias por tu pago! 🙌 Tu sitio sigue activo. Queda el *último 30%* para liquidar tu proyecto: '.Money::format($pr->amount_cents, $pr->currency)."\n"
                    .$this->bankLines($pr)."\n⏳ Tienes *7 días* para liquidarlo.");
            }
            $this->sync($pr);
        } elseif (Str::contains($ref, 'final') && $project) {
            // Final paid → delivered; maintenance starts next month.
            $project->update(['status' => ProjectStatus::Live, 'delivered_at' => now(), 'maintenance_active' => true, 'ready_at' => $project->ready_at ?? now()]);
            if ($conv && $account) {
                $this->gateway->sendText($account->session_name, $conv->contact_jid,
                    '¡Listo! 🎉 Tu proyecto quedó *liquidado y entregado*. El mantenimiento mensual comienza el próximo mes y te aviso por aquí cuando toque. ¡Gracias por confiar en Overcloud! 🙌');
            }
        } elseif ($request->type === PaymentType::Maintenance && $conv && $account) {
            $this->gateway->sendText($account->session_name, $conv->contact_jid, '¡Gracias! 🙌 Tu *mantenimiento* quedó al corriente. Cualquier cambio o ajuste, por aquí. ✅');
        }
    }

    /** Daily: send reminders, pause overdue projects, and raise the monthly maintenance charges. */
    public function runDunning(): void
    {
        PaymentRequest::where('status', PaymentStatus::Pending)
            ->whereIn('type', [PaymentType::Balance, PaymentType::Maintenance])
            ->whereNotNull('due_date')->with(['lead', 'project'])->get()
            ->each(fn (PaymentRequest $pr) => $this->dun($pr));

        $this->billMaintenance();
    }

    private function dun(PaymentRequest $pr): void
    {
        [$conv, $account] = $this->channel($pr->project, $pr->lead_id);
        if (! $conv || ! $account) {
            return;
        }
        $today = now()->startOfDay();
        $due = $pr->due_date->copy()->startOfDay();

        if ($today->gt($due)) {
            $project = $pr->project;
            if ($project && ! $project->paused_at) {
                $this->pauseProject($project);
                $this->gateway->sendText($account->session_name, $conv->contact_jid,
                    'Tu sitio quedó *en pausa* hasta recibir el pago de '.Money::format($pr->amount_cents, $pr->currency).' ('.$pr->reference.'). '
                    ."En cuanto lo recibamos lo reactivamos al instante. 🙏\n".$this->bankLines($pr));
                $pr->update(['reminded_at' => now()]);
            }

            return;
        }

        $daysToDue = (int) $today->diffInDays($due, false);
        $remindedToday = $pr->reminded_at && $pr->reminded_at->copy()->startOfDay()->equalTo($today);
        if ($daysToDue <= 3 && ! $remindedToday) {
            $when = $daysToDue <= 0 ? 'hoy' : "en {$daysToDue} día(s)";
            $this->gateway->sendText($account->session_name, $conv->contact_jid,
                'Recordatorio amable 🙌 Tu pago de '.Money::format($pr->amount_cents, $pr->currency)." ({$pr->reference}) vence {$when}.\n".$this->bankLines($pr));
            $pr->update(['reminded_at' => now()]);
        }
    }

    private function billMaintenance(): void
    {
        Project::whereNotNull('ready_at')->where('maintenance_active', true)->with(['quote', 'lead'])->get()
            ->each(function (Project $project) {
                // Never bill a comped (courtesy) project.
                if ((bool) ($project->brief['comped'] ?? false)) {
                    return;
                }
                if ((int) ($project->quote?->maintenance_monthly_cents ?? 0) <= 0) {
                    return;
                }
                // Maintenance begins the month AFTER everything was ready.
                if (now()->lt($project->ready_at->copy()->addMonth())) {
                    return;
                }
                $last = PaymentRequest::where('project_id', $project->id)->where('type', PaymentType::Maintenance)->latest()->first();
                if ($last && $last->created_at->isSameMonth(now())) {
                    return; // already billed this month
                }
                $pr = $this->payments->createMaintenance($project, self::GRACE_DAYS);
                if (! $pr) {
                    return;
                }
                [$conv, $account] = $this->channel($project);
                if ($conv && $account) {
                    $this->gateway->sendText($account->session_name, $conv->contact_jid,
                        'Tu *mantenimiento mensual* ya está listo para pagarse: '.Money::format($pr->amount_cents, $pr->currency).' ('.$pr->reference.'). '
                        ."Tienes 7 días. ¡Gracias por seguir con Overcloud! 🙌\n".$this->bankLines($pr));
                }
                $this->sync($pr);
            });
    }

    private function pauseProject(Project $project): void
    {
        $this->coolify($project, 'stop');
        $project->update(['paused_at' => now()]);
    }

    private function resumeProject(Project $project): void
    {
        $this->coolify($project, 'start');
        $project->update(['paused_at' => null]);
    }

    private function coolify(Project $project, string $action): void
    {
        if (! $project->coolify_app_uuid) {
            return;
        }
        $c = config('overcloud.deploy');
        try {
            Http::withToken($c['coolify_token'])->timeout(30)
                ->get($c['coolify_url']."/applications/{$project->coolify_app_uuid}/{$action}");
        } catch (\Throwable $e) {
            Log::warning('coolify '.$action.' failed', ['project' => $project->id, 'e' => $e->getMessage()]);
        }
    }

    private function bankLines(PaymentRequest $pr): string
    {
        $s = (array) ($pr->bank_details_snapshot ?? []);
        $lines = array_filter([
            ! empty($s['bank']) ? '🏦 '.$s['bank'] : null,
            ! empty($s['beneficiary']) ? '👤 '.$s['beneficiary'] : null,
            ! empty($s['clabe']) ? '🔢 CLABE: '.$s['clabe'] : null,
            ! empty($s['account_number']) ? '#️⃣ Cuenta: '.$s['account_number'] : null,
            ! empty($s['instructions']) ? $s['instructions'] : null,
            '📝 Concepto: '.$pr->reference,
        ]);

        return implode("\n", $lines)."\nCuando lo hagas, mándame el *comprobante* por aquí. 🙌";
    }

    private function sync(?PaymentRequest $pr): void
    {
        if (! $pr) {
            return;
        }
        try {
            $this->crm->syncPaymentVerified($pr);
        } catch (\Throwable $e) {
            // Dev-Business coordination is best-effort
        }
    }

    /** @return array{0: ?Conversation, 1: mixed} */
    private function channel(?Project $project, ?int $leadId = null): array
    {
        $leadId = $project?->lead_id ?? $leadId;
        if (! $leadId) {
            return [null, null];
        }
        $conv = Conversation::where('lead_id', $leadId)->where('is_group', false)->first();

        return [$conv, $conv?->whatsappAccount];
    }
}
