<?php

namespace App\Console\Commands;

use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Jobs\BuildDemo;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Services\WhatsAppGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Continuously watches every bot conversation for trouble — repeated/spam replies and
 * clients left waiting — pauses the bot on a spam loop, and alerts the owner on WhatsApp
 * so a human can step in. Runs every minute from the scheduler.
 */
class MonitorConversations extends Command
{
    protected $signature = 'bot:monitor';

    protected $description = 'Watch bot conversations for spam loops / stuck clients and alert the owner';

    private const STUCK_MINUTES = 6;

    public function handle(WhatsAppGateway $gateway): int
    {
        $owner = (string) config('overcloud.owner_phone');
        $alerts = 0;

        Conversation::where('is_group', false)
            ->where('updated_at', '>=', now()->subHours(6))
            ->with('lead')->get()
            ->each(function (Conversation $conv) use ($gateway, $owner, &$alerts) {
                $recent = Message::where('conversation_id', $conv->id)->latest()->limit(6)->get();
                if ($recent->isEmpty()) {
                    return;
                }

                // 1) Spam loop: the last 3 bot messages are identical → pause the bot + alert.
                $lastBot = $recent->where('is_from_me', true)->take(3);
                if ($lastBot->count() >= 3 && $lastBot->pluck('body')->map(fn ($b) => trim((string) $b))->unique()->count() === 1) {
                    if ($conv->ai_enabled) {
                        $conv->update(['ai_enabled' => false]);
                    }
                    $this->notifyOwner($gateway, $owner, $conv, '🔁 Bucle de mensajes repetidos — pausé el bot en esta conversación', $alerts);

                    return;
                }

                // 2) Stuck: client wrote last and the bot hasn't answered in a while.
                $last = $recent->first();
                if ($last->direction === MessageDirection::In
                    && $last->created_at->lt(now()->subMinutes(self::STUCK_MINUTES))
                    && $conv->ai_enabled) {
                    $this->notifyOwner($gateway, $owner, $conv, '⏳ Cliente esperando respuesta hace '.self::STUCK_MINUTES.'+ min', $alerts);
                }
            });

        // Also watch the deploy queue (the conversation loop above is blind to it).
        $this->checkDeployQueue($gateway, $owner, $alerts);

        // And watch for demos that were promised but never landed (safety net over BuildDemo's retry).
        $this->checkStuckDemos($gateway, $owner, $alerts);

        $this->info("Monitor done. {$alerts} alert(s).");

        return self::SUCCESS;
    }

    /**
     * Demo watchdog: a lead in negotiating that was promised a demo but has no demo link after a
     * while AND has no demo job in flight → the build died/never ran. Re-dispatch it once and alert.
     */
    private function checkStuckDemos(WhatsAppGateway $gateway, string $owner, int &$alerts): void
    {
        try {
            // Only act when nothing is currently building, so we never pile onto an in-progress demo.
            $demoJobInFlight = DB::table('jobs')
                ->where('queue', 'deploy')->where('payload', 'like', '%BuildDemo%')->exists();
            if ($demoJobInFlight) {
                return;
            }

            Lead::where('stage', LeadStage::Negotiating->value)
                ->where('updated_at', '>=', now()->subHours(12))
                ->with('conversations')->get()
                ->each(function (Lead $lead) use ($gateway, $owner, &$alerts) {
                    $conv = $lead->conversations->firstWhere('is_group', false);
                    if (! $conv || ! $conv->ai_enabled) {
                        return;
                    }
                    // Already has a demo link? Nothing to do.
                    $hasLink = $conv->messages()->where('is_from_me', true)
                        ->where('body', 'like', '%.overcloud.us%')->exists();
                    if ($hasLink) {
                        return;
                    }
                    // Was a demo promised long enough ago to be considered stuck?
                    $promisedAt = $conv->messages()->where('is_from_me', true)
                        ->where('body', 'like', '%demo%')->latest('id')->value('created_at');
                    if (! $promisedAt || Carbon::parse($promisedAt)->gt(now()->subMinutes(25))) {
                        return;
                    }
                    // Re-dispatch once per stuck period (cache guard) and alert the owner.
                    if (! Cache::add('demo-redispatch:'.$lead->id, 1, now()->addHour())) {
                        return;
                    }
                    BuildDemo::dispatch($lead->id)->onQueue('deploy');
                    $who = $lead->company ?: ($lead->name ?: ('lead #'.$lead->id));
                    $this->notifyOwner($gateway, $owner, $conv, "🎨 El demo de {$who} no se había enviado — lo reintenté automáticamente", $alerts);
                });
        } catch (\Throwable $e) {
            Log::warning('checkStuckDemos failed', ['e' => $e->getMessage()]);
        }
    }

    private function notifyOwner(WhatsAppGateway $gateway, string $owner, Conversation $conv, string $reason, int &$alerts): void
    {
        // Don't spam the owner: one alert per conversation+reason per hour. But only burn the
        // dedup key AFTER a confirmed send — if the gateway is down (the very thing worth alerting
        // on), we must be able to alert again next minute instead of silently swallowing it.
        $key = 'mon-alert:'.$conv->id.':'.md5($reason);
        if (Cache::has($key)) {
            return;
        }
        $who = $conv->lead?->company ?: ($conv->lead?->name ?: ($conv->contact_phone ?? 'cliente'));
        $last = Message::where('conversation_id', $conv->id)->latest()->value('body');
        $msg = "⚠️ *Alerta del bot*\n{$reason}\n\n👤 {$who}\n💬 ".Str::limit((string) $last, 80)."\n\nRevisa la conversación en el panel.";
        if ($owner && $conv->whatsappAccount) {
            try {
                $gateway->sendText($conv->whatsappAccount->session_name, $owner.'@s.whatsapp.net', $msg);
                Cache::put($key, 1, now()->addHour());
                $alerts++;
            } catch (\Throwable $e) {
                Log::warning('owner alert failed', ['conv' => $conv->id, 'e' => $e->getMessage()]);
            }
        }
    }

    /**
     * M13: watch the deploy queue itself — a dead worker or a thrown job means a client was
     * promised a build/change that may never land, and the conversation monitor can't see it.
     */
    private function checkDeployQueue(WhatsAppGateway $gateway, string $owner, int &$alerts): void
    {
        try {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours(6))->count();
            // Database queue stores created_at as a unix timestamp; a 'deploy' job sitting >25 min
            // means the worker is stuck/down (real builds finish or fail well within that).
            $stuck = DB::table('jobs')->where('queue', 'deploy')
                ->where('created_at', '<=', now()->subMinutes(25)->getTimestamp())->count();
            if ($failed === 0 && $stuck === 0) {
                return;
            }
            $key = 'mon-queue-alert';
            if (Cache::has($key)) {
                return;
            }
            $parts = [];
            if ($failed > 0) {
                $parts[] = "{$failed} trabajo(s) fallido(s)";
            }
            if ($stuck > 0) {
                $parts[] = "{$stuck} despliegue(s) atascado(s) >25 min";
            }
            $msg = '⚠️ *Cola de despliegues*: '.implode(', ', $parts)
                .'. Un cliente puede estar esperando un cambio o sitio que no llegó. Revisa el worker/panel.';
            $account = WhatsAppAccount::where('session_name', 'overcloud-bot')->first();
            if ($owner && $account) {
                try {
                    $gateway->sendText($account->session_name, $owner.'@s.whatsapp.net', $msg);
                    Cache::put($key, 1, now()->addHour());
                    $alerts++;
                } catch (\Throwable $e) {
                    Log::warning('queue alert failed', ['e' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('checkDeployQueue failed', ['e' => $e->getMessage()]);
        }
    }
}
