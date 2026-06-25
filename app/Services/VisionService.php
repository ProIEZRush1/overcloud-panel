<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lets the bot "see" inbound images. Claude Code reads images natively, so we drop
 * the media to a temp file and have the CLI (as the non-root builder, which may use
 * --dangerously-skip-permissions) read + describe it. The description lands in the
 * message body so the funnel and the reply model treat it like any other text.
 */
class VisionService
{
    public function isEnabled(): bool
    {
        return (bool) config('overcloud.ai.enabled') && config('overcloud.ai.driver') === 'claude_code';
    }

    public function describe(Message $message): void
    {
        if ($message->type !== MessageType::Image || ! $message->media_path || ! $this->isEnabled()) {
            return;
        }
        // Already has real (non-derived) text from the client.
        if (filled($message->body) && ! Str::startsWith($message->body, '[')) {
            return;
        }

        $tmp = null;
        try {
            $bytes = Storage::exists($message->media_path) ? Storage::get($message->media_path) : null;
            if (! $bytes) {
                return;
            }
            $ext = match ($message->media_mime) {
                'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => 'jpg',
            };
            $tmp = '/tmp/vision-'.$message->id.'.'.$ext;
            file_put_contents($tmp, $bytes);
            @chmod($tmp, 0644);

            $caption = $message->caption ? 'El cliente escribió junto a la imagen: "'.$message->caption.'". ' : '';
            $prompt = $caption.'Lee la imagen en '.$tmp.' y descríbela en detalle para un asistente de ventas de una agencia web (Overcloud): '
                .'qué es, qué muestra, y TRANSCRIBE todo el texto, datos o cifras visibles. Si es un documento, formulario o captura, explica su propósito. '
                .'Responde solo con la descripción, en español, sin preámbulos.';

            $inner = 'HOME=/home/builder claude -p '.escapeshellarg($prompt)
                .' --dangerously-skip-permissions --model '.escapeshellarg((string) config('overcloud.ai.model', 'sonnet'));
            $r = Process::timeout((int) config('overcloud.ai.vision_timeout', 120))->run(['su', 'builder', '-c', $inner]);

            $desc = trim($r->output());
            if ($r->successful() && $desc !== '') {
                $message->update(['body' => trim('[Imagen] '.$desc)]);
            }
        } catch (\Throwable $e) {
            Log::warning('Vision describe failed', ['msg' => $message->id, 'e' => $e->getMessage()]);
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }
    }
}
