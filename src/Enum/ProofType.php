<?php

namespace App\Enum;

enum ProofType: string
{
    case GITHUB = 'github';
    case YOUTUBE = 'youtube';
    case SITE = 'site';
    case MEMOIRE = 'memoire';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::GITHUB => 'Code source (GitHub)',
            self::YOUTUBE => 'Vidéo YouTube',
            self::SITE => 'Site web ou démo',
            self::MEMOIRE => 'Mémoire (lien externe)',
            self::AUTRE => 'Autre preuve',
        };
    }

    /** Forme courte utilisée sur les cartes de projet (ex. "GitHub · Vidéo · Mémoire"). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::GITHUB => 'GitHub',
            self::YOUTUBE => 'Vidéo',
            self::SITE => 'Site',
            self::MEMOIRE => 'Mémoire',
            self::AUTRE => 'Autre',
        };
    }
}
