<?php

namespace App\Enums;

enum WhatsAppAccountStatus: string
{
    case Disconnected = 'disconnected';
    case QrPending = 'qr_pending';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case LoggedOut = 'logged_out';

    public function label(): string
    {
        return match ($this) {
            self::Disconnected => 'Desconectado',
            self::QrPending => 'Esperando QR',
            self::Connecting => 'Conectando',
            self::Connected => 'Conectado',
            self::LoggedOut => 'Sesión cerrada',
        };
    }

    public function isLive(): bool
    {
        return $this === self::Connected;
    }
}
