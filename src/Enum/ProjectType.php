<?php

namespace App\Enum;

enum ProjectType: string
{
    case SOUTENANCE = 'soutenance';
    case PERSONNEL = 'personnel';
    case PROFESSIONNEL = 'professionnel';
    case ENTREPRENEURIAL = 'entrepreneurial';
    case RECHERCHE = 'recherche';

    public function label(): string
    {
        return match ($this) {
            self::SOUTENANCE => 'Projet de soutenance',
            self::PERSONNEL => 'Projet personnel',
            self::PROFESSIONNEL => 'Projet professionnel',
            self::ENTREPRENEURIAL => 'Projet entrepreneurial',
            self::RECHERCHE => 'Projet de recherche',
        };
    }

    /** Forme courte utilisée sur les badges et filtres (code/explorer.html). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::SOUTENANCE => 'Soutenance',
            self::PERSONNEL => 'Personnel',
            self::PROFESSIONNEL => 'Professionnel',
            self::ENTREPRENEURIAL => 'Entrepreneurial',
            self::RECHERCHE => 'Recherche',
        };
    }
}
