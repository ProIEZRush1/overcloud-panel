<?php

namespace App\Enums;

enum PaymentType: string
{
    case Deposit = 'deposit';
    case Balance = 'balance';
    case Full = 'full';
    case Maintenance = 'maintenance';
    case Extra = 'extra';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Anticipo',
            self::Balance => 'Liquidación',
            self::Full => 'Pago completo',
            self::Maintenance => 'Mantenimiento',
            self::Extra => 'Adicional',
        };
    }
}
