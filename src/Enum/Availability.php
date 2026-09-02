<?php

namespace App\Enum;

enum Availability: string
{
    case STAGE = 'stage';
    case ALTERNANCE = 'alternance';
    case CDI = 'cdi';

    public function label(): string
    {
        return match ($this) {
            self::STAGE => 'Stage',
            self::ALTERNANCE => 'Alternance',
            self::CDI => 'CDI',
        };
    }
}
