<?php

namespace App\Enum;

/**
 * Statut du résultat académique d'une soutenance — distinct du statut de
 * vérification de la soutenance elle-même (cahier des charges §18/§21 :
 * "Ne mélange surtout pas... Soutenance vérifiée / Résultat de soutenance").
 */
enum DefenseResultStatus: string
{
    case EN_ATTENTE = 'en_attente';
    case REUSSIE = 'reussie';
    case ECHOUEE = 'echouee';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::REUSSIE => 'Réussie',
            self::ECHOUEE => 'Échouée',
        };
    }
}
