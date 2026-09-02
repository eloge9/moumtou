<?php

namespace App\Enum;

enum JuryStatus: string
{
    case EN_ATTENTE = 'en_attente';
    case CONFIRME = 'confirme';
    case REFUSE = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::CONFIRME => 'Confirmé',
            self::REFUSE => 'Refusé',
        };
    }
}
