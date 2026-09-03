<?php

namespace App\Enum;

/**
 * Statut d'une demande de vérification (cahier des charges — FONCTIONNALITÉ
 * 14 §5). NON_DEMANDEE n'est jamais persisté : c'est l'état déduit de
 * l'absence de {@see \App\Entity\VerificationRequest} pour une cible donnée.
 */
enum VerificationStatus: string
{
    case EN_ATTENTE = 'en_attente';
    case EN_VERIFICATION = 'en_verification';
    case VERIFIEE = 'verifiee';
    case CORRECTION_DEMANDEE = 'correction_demandee';
    case REFUSEE = 'refusee';
    case RETIREE = 'retiree';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::EN_VERIFICATION => 'En cours d\'examen',
            self::VERIFIEE => 'Vérifiée',
            self::CORRECTION_DEMANDEE => 'Correction demandée',
            self::REFUSEE => 'Refusée',
            self::RETIREE => 'Retirée',
        };
    }

    /** Une demande "ouverte" ne peut pas en cohabiter une seconde pour la même cible. */
    public function isOpen(): bool
    {
        return \in_array($this, [self::EN_ATTENTE, self::EN_VERIFICATION, self::CORRECTION_DEMANDEE], true);
    }
}
