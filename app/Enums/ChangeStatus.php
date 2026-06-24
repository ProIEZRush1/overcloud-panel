<?php

namespace App\Enums;

enum ChangeStatus: string
{
    case Pending = 'pending';
    case Quoted = 'quoted';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Quoted => 'Cotizado',
            self::Approved => 'Aprobado',
            self::InProgress => 'En progreso',
            self::Done => 'Listo',
            self::Rejected => 'Rechazado',
        };
    }
}
