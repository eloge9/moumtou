<?php

namespace App\Enum;

enum ModerationActionType: string
{
    case PUBLIER = 'publier';
    case DEPUBLIER = 'depublier';
    case MASQUER = 'masquer';
    case SUPPRIMER = 'supprimer';
    case DEMANDER_CORRECTION = 'demander_correction';
    case MARQUER_VERIFIE = 'marquer_verifie';
    case AVERTIR = 'avertir';
    case SUSPENDRE = 'suspendre';
    case BANNIR = 'bannir';

    public function label(): string
    {
        return match ($this) {
            self::PUBLIER => 'Publier',
            self::DEPUBLIER => 'Dépublier',
            self::MASQUER => 'Masquer le projet',
            self::SUPPRIMER => 'Supprimer le projet',
            self::DEMANDER_CORRECTION => 'Demander des corrections',
            self::MARQUER_VERIFIE => 'Marquer comme vérifié',
            self::AVERTIR => 'Avertir l\'auteur',
            self::SUSPENDRE => 'Suspendre l\'auteur',
            self::BANNIR => 'Bannir l\'auteur',
        };
    }
}
