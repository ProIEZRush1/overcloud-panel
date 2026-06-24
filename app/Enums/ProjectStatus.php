<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Review = 'review';
    case Live = 'live';
    case Maintenance = 'maintenance';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::Building => 'Construyendo',
            self::Review => 'Revisión',
            self::Live => 'En línea',
            self::Maintenance => 'Mantenimiento',
            self::Paused => 'Pausado',
            self::Cancelled => 'Cancelado',
        };
    }
}
