<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\BotResponder;
use App\Services\TranscriptionService;
use App\Services\VisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Generates and sends the AI reply for an inbound message. Runs on the queue so
 * the Claude Code CLI executes in the worker's login-shell context (PATH +
 * keychain), not under php-fpm.
 */
class GenerateBotReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $messageId) {}

    public function handle(BotResponder $responder, TranscriptionService $transcriber, VisionService $vision): void
    {
        // Reply to each inbound at most once, even if the job is dispatched/retried twice.
        if (! Cache::add('bot-replied:'.$this->messageId, 1, now()->addMinutes(15))) {
            return;
        }

        $message = Message::with('conversation.whatsappAccount', 'conversation.lead')->find($this->messageId);

        if ($message) {
            // Make the message readable: voice notes → text, images → description.
            $transcriber->transcribe($message);
            $vision->describe($message);
            $responder->handle($message->conversation, $message->fresh());
        }
    }
}
