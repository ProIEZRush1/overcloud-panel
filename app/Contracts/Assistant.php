<?php

namespace App\Contracts;

interface Assistant
{
    public function isEnabled(): bool;

    /**
     * Generate the assistant's next reply for a conversation.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function message(string $system, array $messages, ?int $maxTokens = null): ?string;

    /**
     * Raw completion for structured output (e.g. JSON) — no conversational persona.
     */
    public function complete(string $prompt, ?int $maxTokens = null): ?string;
}
