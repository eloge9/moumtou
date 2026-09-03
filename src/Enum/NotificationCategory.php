<?php

namespace App\Enum;

/**
 * Regroupement des types de notification pour les préférences utilisateur
 * (cahier des charges — FONCTIONNALITÉ 8 §24) : plus fin que "tout ou rien",
 * plus simple qu'une préférence par type exact.
 */
enum NotificationCategory: string
{
    case CONTACT = 'contact';
    case SOUTENANCE = 'soutenance';
    case PROJET = 'projet';
    case COMMUNAUTE = 'communaute';
    case SECURITE = 'securite';
    case MODERATION = 'moderation';

    public function label(): string
    {
        return match ($this) {
            self::CONTACT => 'Demandes de contact',
            self::SOUTENANCE => 'Soutenance et jury',
            self::PROJET => 'Projets',
            self::COMMUNAUTE => 'Commentaires et évaluations',
            self::SECURITE => 'Sécurité du compte',
            self::MODERATION => 'Modération (administrateurs)',
        };
    }

    /**
     * Cahier des charges §24 : les notifications de sécurité ne peuvent
     * jamais être désactivées, ni en interne ni par e-mail.
     */
    public function isMandatory(): bool
    {
        return self::SECURITE === $this;
    }

    /**
     * Cahier des charges §23 : commentaires/évaluations restent en
     * notification interne uniquement par défaut, pour éviter le spam.
     */
    public function defaultEmailEnabled(): bool
    {
        return !\in_array($this, [self::COMMUNAUTE, self::MODERATION], true);
    }
}
