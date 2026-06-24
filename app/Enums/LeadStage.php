<?php

namespace App\Enums;

enum LeadStage: string
{
    case New = 'new';
    case Qualifying = 'qualifying';
    case Spec = 'spec';
    case Quoted = 'quoted';
    case Negotiating = 'negotiating';
    case Accepted = 'accepted';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case InProduction = 'in_production';
    case Review = 'review';
    case Delivered = 'delivered';
    case Maintenance = 'maintenance';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nuevo',
            self::Qualifying => 'Calificando',
            self::Spec => 'Alcance',
            self::Quoted => 'Cotizado',
            self::Negotiating => 'Negociando',
            self::Accepted => 'Aceptado',
            self::AwaitingPayment => 'Esperando pago',
            self::Paid => 'Pagado',
            self::InProduction => 'En producción',
            self::Review => 'Revisión',
            self::Delivered => 'Entregado',
            self::Maintenance => 'Mantenimiento',
            self::Lost => 'Perdido',
        };
    }

    /** Open stages that the bot/agent actively works. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Maintenance, self::Lost], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::New, self::Qualifying => 'blue',
            self::Spec, self::Quoted, self::Negotiating => 'amber',
            self::Accepted, self::AwaitingPayment => 'violet',
            self::Paid, self::InProduction, self::Review => 'indigo',
            self::Delivered, self::Maintenance => 'green',
            self::Lost => 'red',
        };
    }
}
