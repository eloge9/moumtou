<?php

namespace App\Enum;

enum DefenseDecision: string
{
    case EN_ATTENTE = 'en_attente';
    case ADMIS = 'admis';
    case AJOURNE = 'ajourne';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::ADMIS => 'Admis(e)',
            self::AJOURNE => 'Ajourné(e)',
        };
    }
}
