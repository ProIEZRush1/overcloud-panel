<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Bot = 'bot';
    case Human = 'human';
    case Snoozed = 'snoozed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Bot => 'Automático',
            self::Human => 'Manual',
            self::Snoozed => 'Pospuesto',
            self::Closed => 'Cerrado',
        };
    }

    /** Whether the AI agent is allowed to auto-reply in this conversation. */
    public function botMayReply(): bool
    {
        return $this === self::Bot;
    }
}
