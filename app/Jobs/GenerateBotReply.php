<?php

namespace App\Jobs;

use App\Enums\MessageType;
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
        if (! $message) {
            return;
        }

        // Debounce fragmented messages ("Pero", "T", "R"…): if the client already sent a newer
        // message, skip this one — the latest message's job produces ONE combined reply.
        $hasNewer = Message::where('conversation_id', $message->conversation_id)
            ->where('is_from_me', false)
            ->where('id', '>', $message->id)
            ->exists();
        if ($hasNewer) {
            return;
        }

        // A reaction (👍) or empty/system event is NOT a message — never reply to it. (The gateway
        // already filters these; this is defense in depth in case one slips through.)
        if (blank($message->body) && blank($message->media_path) && blank($message->caption)
            && ! in_array($message->type, [MessageType::Audio, MessageType::Image, MessageType::Document], true)) {
            return;
        }

        // Make the message readable: voice notes → text, images → description.
        $transcriber->transcribe($message);
        $vision->describe($message);
        $message->refresh();

        // A voice note we couldn't transcribe (e.g. too large to download) must NOT be turned into a
        // fake "[el cliente envió un audio]" body and run through the funnel — that would have the bot
        // act on a guessed instruction. Ask the client to resend instead, and stop here.
        if (blank($message->body) && $message->type === MessageType::Audio) {
            $responder->notice($message->conversation,
                'Perdón, no pude escuchar bien tu audio 🙈 ¿Me lo puedes escribir o reenviar? Con gusto te ayudo enseguida. 🙌');

            return;
        }

        // If transcription/vision failed and there's still no text, fall back to the caption
        // or a sensible placeholder so the bot still engages instead of choking on an empty body.
        if (blank($message->body)) {
            $fallback = filled($message->caption)
                ? $message->caption
                : match ($message->type->value) {
                    'image' => '[el cliente envió una imagen]',
                    'document' => '[el cliente envió un documento]',
                    default => '[el cliente envió un mensaje]',
                };
            $message->update(['body' => $fallback]);
        }

        $responder->handle($message->conversation, $message->fresh());
    }
}
