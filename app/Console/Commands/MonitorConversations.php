<?php

namespace App\Console\Commands;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsAppGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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

        $this->info("Monitor done. {$alerts} alert(s).");

        return self::SUCCESS;
    }

    private function notifyOwner(WhatsAppGateway $gateway, string $owner, Conversation $conv, string $reason, int &$alerts): void
    {
        // Don't spam the owner: one alert per conversation+reason per hour.
        $key = 'mon-alert:'.$conv->id.':'.md5($reason);
        if (! Cache::add($key, 1, now()->addHour())) {
            return;
        }
        $who = $conv->lead?->company ?: ($conv->lead?->name ?: ($conv->contact_phone ?? 'cliente'));
        $last = Message::where('conversation_id', $conv->id)->latest()->value('body');
        $msg = "⚠️ *Alerta del bot*\n{$reason}\n\n👤 {$who}\n💬 ".Str::limit((string) $last, 80)."\n\nRevisa la conversación en el panel.";
        if ($owner && $conv->whatsappAccount) {
            try {
                $gateway->sendText($conv->whatsappAccount->session_name, $owner.'@s.whatsapp.net', $msg);
            } catch (\Throwable $e) {
            }
        }
        $alerts++;
    }
}
