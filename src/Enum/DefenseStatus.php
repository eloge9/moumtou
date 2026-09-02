<?php

namespace App\Enum;

enum DefenseStatus: string
{
    case ANNONCEE = 'annoncee';
    case REALISEE = 'realisee';
    case VERIFIEE = 'verifiee';

    public function label(): string
    {
        return match ($this) {
            self::ANNONCEE => 'Annoncée',
            self::REALISEE => 'Réalisée',
            self::VERIFIEE => 'Vérifiée',
        };
    }
}
