<?php

namespace App\Enum;

/**
 * Demande de contact recruteur → talent (cahier des charges — FONCTIONNALITÉ
 * 7 §13/§14).
 */
enum ContactRequestStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REFUSED = 'refused';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::ACCEPTED => 'Acceptée',
            self::REFUSED => 'Refusée',
            self::CANCELLED => 'Annulée',
        };
    }
}
