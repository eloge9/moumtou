<?php

namespace App\Enum;

enum ReportTargetType: string
{
    case PROJECT = 'project';
    case PROFILE = 'profile';
    case COMMENT = 'comment';

    public function label(): string
    {
        return match ($this) {
            self::PROJECT => 'Projet',
            self::PROFILE => 'Profil',
            self::COMMENT => 'Commentaire',
        };
    }
}
