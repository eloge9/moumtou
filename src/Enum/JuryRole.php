<?php

namespace App\Enum;

enum JuryRole: string
{
    case PRESIDENT = 'president';
    case RAPPORTEUR = 'rapporteur';
    case EXAMINATEUR = 'examinateur';
    case DIRECTEUR_MEMOIRE = 'directeur_memoire';
    case ENCADREUR = 'encadreur';

    public function label(): string
    {
        return match ($this) {
            self::PRESIDENT => 'Président',
            self::RAPPORTEUR => 'Rapporteur',
            self::EXAMINATEUR => 'Examinateur',
            self::DIRECTEUR_MEMOIRE => 'Directeur de mémoire',
            self::ENCADREUR => 'Encadreur',
        };
    }
}
