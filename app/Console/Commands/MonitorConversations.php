<?php

namespace App\Console\Commands;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Services\WhatsAppGateway;
use Illuminate\Console\Command;
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

        $this->info("Monitor done. {$alerts} alert(s).");

        return self::SUCCESS;
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
