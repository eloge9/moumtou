<?php

namespace App\Enum;

enum UserStatus: string
{
    case ACTIF = 'actif';
    case SUSPENDU = 'suspendu';
    case BANNI = 'banni';

    public function label(): string
    {
        return match ($this) {
            self::ACTIF => 'Actif',
            self::SUSPENDU => 'Suspendu',
            self::BANNI => 'Banni',
        };
    }
}
