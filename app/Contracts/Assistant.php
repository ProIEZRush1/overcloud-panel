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
}
