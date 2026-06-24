<?php

namespace App\Enums;

enum CredentialKind: string
{
    case DomainRegistrar = 'domain_registrar';
    case Dns = 'dns';
    case Smtp = 'smtp';
    case Email = 'email';
    case Hosting = 'hosting';
    case Analytics = 'analytics';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DomainRegistrar => 'Registrador de dominio',
            self::Dns => 'DNS',
            self::Smtp => 'SMTP',
            self::Email => 'Correo',
            self::Hosting => 'Hosting',
            self::Analytics => 'Analítica',
            self::Other => 'Otro',
        };
    }
}
