<?php

namespace App\Enum;

/**
 * Détection des comportements anormaux (cahier des charges §10) : structure
 * simple laissant l'administrateur examiner les évaluations suspectes, sans
 * blocage automatique.
 */
enum RatingStatus: string
{
    case NORMAL = 'normal';
    case SUSPECT = 'suspect';
    case FLAGGED = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normale',
            self::SUSPECT => 'Suspecte',
            self::FLAGGED => 'Signalée',
        };
    }
}
