<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\BotResponder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public int $timeout = 120;

    public function __construct(public int $messageId) {}

    public function handle(BotResponder $responder): void
    {
        $message = Message::with('conversation.whatsappAccount', 'conversation.lead')->find($this->messageId);

        if ($message) {
            $responder->handle($message->conversation, $message);
        }
    }
}
