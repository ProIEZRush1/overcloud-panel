<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Transcribes WhatsApp voice notes / audio messages to text (OpenAI Whisper) so
 * the bot reads them like any other message. No key configured = graceful no-op.
 */
class TranscriptionService
{
    public function isEnabled(): bool
    {
        return filled(config('overcloud.ai.whisper_url')) || filled(config('overcloud.ai.openai_key'));
    }

    /** Transcribe an inbound audio message in place; returns the text or null. */
    public function transcribe(Message $message): ?string
    {
        if ($message->type !== MessageType::Audio || ! $message->media_path || ! $this->isEnabled()) {
            return null;
        }
        if (filled($message->body) || ! Storage::exists($message->media_path)) {
            return null;
        }

        try {
            // Free local Whisper server first (no API cost); OpenAI only as fallback.
            $local = config('overcloud.ai.whisper_url');
            $base = $local ? rtrim($local, '/') : 'https://api.openai.com';

            $request = Http::timeout(120)
                ->attach('file', Storage::get($message->media_path), 'audio.ogg');
            if (! $local) {
                $request = $request->withToken(config('overcloud.ai.openai_key'));
            }

            $response = $request->post($base.'/v1/audio/transcriptions', [
                'model' => config('overcloud.ai.transcribe_model', 'whisper-1'),
            ]);

            if (! $response->successful()) {
                Log::warning('Transcription failed', ['status' => $response->status()]);

                return null;
            }

            $text = trim((string) $response->json('text'));
            if ($text === '') {
                return null;
            }

            $message->update(['body' => $text]);
            $message->conversation?->forceFill([
                'last_message_preview' => '🎤 '.Str::limit($text, 110),
            ])->save();

            return $text;
        } catch (\Throwable $e) {
            Log::warning('Transcription errored', ['e' => $e->getMessage()]);

            return null;
        }
    }
}
