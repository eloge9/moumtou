<?php

namespace App\Enum;

enum ReportStatus: string
{
    case OUVERT = 'ouvert';
    case EN_COURS = 'en_cours';
    case TRAITE = 'traite';

    public function label(): string
    {
        return match ($this) {
            self::OUVERT => 'Ouvert',
            self::EN_COURS => 'En cours',
            self::TRAITE => 'Traité',
        };
    }
}
