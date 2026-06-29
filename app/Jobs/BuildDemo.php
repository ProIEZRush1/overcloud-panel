<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Lead;
use App\Services\DeployService;
use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds + deploys a quick visual demo for a lead BEFORE the quote, then shares the
 * link so the client falls in love. Only messages the client on success (no errors).
 */
class BuildDemo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    /** A demo must reliably land — keep retrying until it's live (or we exhaust attempts). */
    private const MAX_ATTEMPTS = 4;

    /** Survive a worker restart (deploys): retry a killed build instead of stranding the client. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(90);
    }

    public function __construct(public int $leadId, public int $attempt = 1) {}

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $lead = Lead::with('service')->find($this->leadId);
        if (! $lead) {
            return;
        }

        $url = $deploy->deployDemo($lead);
        if (! $url) {
            // Demos must never silently die: auto-retry with backoff. Only alert the owner once
            // attempts are exhausted (never tell the client). The next attempt re-runs the full build.
            if ($this->attempt < self::MAX_ATTEMPTS) {
                self::dispatch($this->leadId, $this->attempt + 1)
                    ->onQueue('deploy')
                    ->delay(now()->addSeconds(60 * $this->attempt));
            } else {
                $deploy->reportDemoProgress($lead, 2, false, true); // progress page shows a soft "still working"
                $deploy->alertOwner('🎨 El demo de "'.($lead->company ?: $lead->name ?: ('lead #'.$lead->id))
                    .'" no quedó en línea tras '.self::MAX_ATTEMPTS.' intentos. Requiere revisión manual.');
            }

            return; // never surface an error to the client
        }

        // Demo is live → finish the progress page (and stamp the URL so its button opens the demo).
        // Also start its 5-day clock so trials:expire tears the demo down if the lead doesn't convert.
        $deploy->reportDemoProgress($lead, 3, true);
        try {
            $meta = (array) $lead->fresh()->meta;
            $meta['progress']['url'] = $url;
            $meta['demo'] = ['url' => $url, 'delivered_at' => now()->toIso8601String(), 'expires_at' => now()->addDays(5)->toIso8601String()];
            $lead->update(['meta' => $meta]);
        } catch (\Throwable $e) {
        }

        $conv = $lead->conversations()->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;
        if ($conv && $account) {
            $text = "¡Aquí está tu *demo*! 🎨 Mira cómo se vería tu proyecto en vivo:\n{$url}\n\n"
                ."⏳ Este demo está disponible *5 días* para que lo revises sin costo. Si te late, te paso la *cotización* y lo dejamos fijo. ✅\n\n¿Qué te parece?";
            $out = $conv->messages()->create([
                'direction' => MessageDirection::Out,
                'type' => MessageType::Text,
                'body' => $text,
                'status' => MessageStatus::Pending,
                'is_from_me' => true,
                'ai_generated' => true,
                'wa_timestamp' => now(),
            ]);
            $r = $gateway->sendText($account->session_name, $conv->contact_jid, $text);
            $out->update([
                'status' => ! empty($r['wa_message_id']) ? MessageStatus::Sent : MessageStatus::Pending,
                'wa_message_id' => $r['wa_message_id'] ?? null,
            ]);
        }
    }
}
