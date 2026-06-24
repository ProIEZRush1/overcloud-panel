<?php

namespace App\Enums;

enum SpecStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case ChangesRequested = 'changes_requested';
    case Agreed = 'agreed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Sent => 'Enviado',
            self::ChangesRequested => 'Cambios solicitados',
            self::Agreed => 'Aprobado',
        };
    }
}
