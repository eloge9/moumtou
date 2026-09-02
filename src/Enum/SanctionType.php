<?php

namespace App\Enum;

enum SanctionType: string
{
    case AVERTISSEMENT = 'avertissement';
    case SUSPENSION = 'suspension';
    case BANNISSEMENT = 'bannissement';

    public function label(): string
    {
        return match ($this) {
            self::AVERTISSEMENT => 'Avertissement',
            self::SUSPENSION => 'Suspension',
            self::BANNISSEMENT => 'Bannissement',
        };
    }
}
