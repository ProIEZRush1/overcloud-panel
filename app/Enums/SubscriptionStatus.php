<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::PastDue => 'Vencida',
            self::Paused => 'Pausada',
            self::Cancelled => 'Cancelada',
        };
    }
}
