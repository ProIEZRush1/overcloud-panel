<?php

namespace App\Support;

class Money
{
    /** Format integer cents as a localized currency string, e.g. 1234500 => "$12,345.00". */
    public static function format(int $cents, string $currency = 'MXN'): string
    {
        $symbol = match ($currency) {
            'MXN', 'USD' => '$',
            'EUR' => '€',
            default => '',
        };

        return $symbol.number_format($cents / 100, 2).' '.$currency;
    }

    public static function pesos(int $cents): float
    {
        return round($cents / 100, 2);
    }

    public static function toCents(int|float $amount): int
    {
        return (int) round($amount * 100);
    }
}
