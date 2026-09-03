<?php

namespace App\Enum;

enum ProofType: string
{
    case GITHUB = 'github';
    case YOUTUBE = 'youtube';
    case SITE = 'site';
    case DEMO = 'demo';
    case MEMOIRE = 'memoire';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::GITHUB => 'Code source (GitHub)',
            self::YOUTUBE => 'Vidéo YouTube',
            self::SITE => 'Site web',
            self::DEMO => 'Démo',
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
            self::DEMO => 'Démo',
            self::MEMOIRE => 'Mémoire',
            self::AUTRE => 'Autre',
        };
    }

    /** Libellé du bouton d'action affiché sur la page publique du projet. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::GITHUB => 'Voir sur GitHub',
            self::YOUTUBE => 'Voir la vidéo',
            self::SITE => 'Visiter le site',
            self::DEMO => 'Voir la démo',
            self::MEMOIRE => 'Consulter le mémoire',
            self::AUTRE => 'Voir la preuve',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GITHUB => '💻',
            self::YOUTUBE => '🎥',
            self::SITE => '🌐',
            self::DEMO => '🚀',
            self::MEMOIRE => '📚',
            self::AUTRE => '🔗',
        };
    }
}
