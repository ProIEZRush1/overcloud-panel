<?php

namespace App\Jobs;

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

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $leadId) {}

    public function handle(DeployService $deploy, WhatsAppGateway $gateway): void
    {
        $lead = Lead::with('service')->find($this->leadId);
        if (! $lead) {
            return;
        }

        $url = $deploy->deployDemo($lead);
        if (! $url) {
            return; // never surface an error to the client
        }

        $conv = $lead->conversations()->where('is_group', false)->first();
        $account = $conv?->whatsappAccount;
        if ($conv && $account) {
            $gateway->sendText($account->session_name, $conv->contact_jid,
                "¡Aquí está tu *demo*! 🎨 Mira cómo se vería tu proyecto en vivo:\n{$url}\n\n¿Qué te parece? Si te late, te paso la *cotización* para arrancar. ✅");
        }
    }
}
