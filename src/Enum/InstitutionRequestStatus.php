<?php

namespace App\Enum;

enum InstitutionRequestStatus: string
{
    case EN_ATTENTE = 'en_attente';
    case ACCEPTEE = 'acceptee';
    case REFUSEE = 'refusee';
    case CORRECTIONS_DEMANDEES = 'corrections_demandees';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::ACCEPTEE => 'Acceptée',
            self::REFUSEE => 'Refusée',
            self::CORRECTIONS_DEMANDEES => 'Corrections demandées',
        };
    }
}
