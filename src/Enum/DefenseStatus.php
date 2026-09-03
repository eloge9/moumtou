<?php

namespace App\Enum;

enum DefenseStatus: string
{
    case ANNONCEE = 'annoncee';
    case REPORTEE = 'reportee';
    case ANNULEE = 'annulee';
    case REALISEE = 'realisee';
    case VERIFIEE = 'verifiee';

    public function label(): string
    {
        return match ($this) {
            self::ANNONCEE => 'Annoncée',
            self::REPORTEE => 'Reportée',
            self::ANNULEE => 'Annulée',
            self::REALISEE => 'Réalisée',
            self::VERIFIEE => 'Vérifiée',
        };
    }
}
