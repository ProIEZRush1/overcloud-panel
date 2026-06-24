<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Minimal Anthropic Messages API client. Returns null when no key is configured. */
class ClaudeClient
{
    public function isEnabled(): bool
    {
        return config('overcloud.ai.enabled') && filled(config('overcloud.ai.key'));
    }

    /**
     * @param  array<int, array{role:string, content:string}>  $messages
     */
    public function message(string $system, array $messages, ?int $maxTokens = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('overcloud.ai.key'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('overcloud.ai.model'),
                'max_tokens' => $maxTokens ?? config('overcloud.ai.max_tokens'),
                'system' => $system,
                'messages' => $messages,
            ]);

            if ($response->failed()) {
                Log::warning('Claude request failed', ['status' => $response->status()]);

                return null;
            }

            return $response->json('content.0.text');
        } catch (\Throwable $e) {
            Log::warning('Claude request errored', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
