<?php

namespace App\Enum;

/**
 * Cahier des charges (gestion des établissements) §2 : type contrôlé
 * plutôt qu'un champ texte libre.
 */
enum InstitutionType: string
{
    case UNIVERSITE = 'universite';
    case ECOLE = 'ecole';
    case INSTITUT = 'institut';
    case CENTRE_DE_FORMATION = 'centre_de_formation';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::UNIVERSITE => 'Université',
            self::ECOLE => 'École',
            self::INSTITUT => 'Institut',
            self::CENTRE_DE_FORMATION => 'Centre de formation',
            self::AUTRE => 'Autre',
        };
    }
}
