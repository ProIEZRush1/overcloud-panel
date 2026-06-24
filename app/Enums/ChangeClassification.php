<?php

namespace App\Enums;

enum ChangeClassification: string
{
    /** Covered by the agreed spec or the maintenance plan — done for free. */
    case InScope = 'in_scope';
    /** Outside the agreed spec — must be re-quoted. */
    case OutOfScope = 'out_of_scope';
    /** A brand-new feature — must be quoted as additional work. */
    case NewFeature = 'new_feature';

    public function label(): string
    {
        return match ($this) {
            self::InScope => 'Dentro del alcance',
            self::OutOfScope => 'Fuera del alcance',
            self::NewFeature => 'Nueva funcionalidad',
        };
    }

    public function needsQuote(): bool
    {
        return $this !== self::InScope;
    }
}
