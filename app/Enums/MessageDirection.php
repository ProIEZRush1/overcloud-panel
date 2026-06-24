<?php

namespace App\Enums;

enum MessageDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return $this === self::In ? 'Entrante' : 'Saliente';
    }
}
