<?php

namespace App\Enum;

/**
 * Types de document associable à un projet (cahier des charges —
 * FONCTIONNALITÉ 10 §9) : rapport, présentation, documentation, publication
 * ou autre document autorisé. Distinct des preuves-liens ({@see ProofType})
 * : un document est un fichier réellement hébergé sur MOUMTOU, pas un lien
 * externe.
 */
enum ProjectDocumentType: string
{
    case RAPPORT = 'rapport';
    case PRESENTATION = 'presentation';
    case DOCUMENTATION = 'documentation';
    case PUBLICATION = 'publication';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::RAPPORT => 'Rapport',
            self::PRESENTATION => 'Présentation',
            self::DOCUMENTATION => 'Documentation',
            self::PUBLICATION => 'Publication',
            self::AUTRE => 'Autre document',
        };
    }
}
