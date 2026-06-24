<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case ProofSubmitted = 'proof_submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::ProofSubmitted => 'Comprobante recibido',
            self::Verified => 'Verificado',
            self::Rejected => 'Rechazado',
        };
    }

    public function needsReview(): bool
    {
        return $this === self::ProofSubmitted;
    }
}
