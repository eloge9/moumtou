<?php

namespace App\Enum;

/**
 * Cycle de vie d'un projet (cahier des charges §30 et §45 fusionnés,
 * cf. contradiction signalée et validée avec le porteur du projet).
 */
enum ProjectStatus: string
{
    case BROUILLON = 'brouillon';
    case EN_ATTENTE = 'en_attente';
    case PUBLIE = 'publie';
    case VERIFICATION_DEMANDEE = 'verification_demandee';
    case VERIFIE = 'verifie';
    case REJETE = 'rejete';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::EN_ATTENTE => 'En attente de modération',
            self::PUBLIE => 'Publié',
            self::VERIFICATION_DEMANDEE => 'Vérification demandée',
            self::VERIFIE => 'Vérifié',
            self::REJETE => 'Rejeté',
        };
    }
}
