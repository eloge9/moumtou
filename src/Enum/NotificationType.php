<?php

namespace App\Enum;

/**
 * Types de notification (cahier des charges — FONCTIONNALITÉ 8 §6) :
 * uniquement les événements qui existent réellement dans MOUMTOU —
 * architecture pensée pour rester facilement extensible.
 */
enum NotificationType: string
{
    case CONTACT_REQUEST_RECEIVED = 'contact_request_received';
    case CONTACT_REQUEST_ACCEPTED = 'contact_request_accepted';
    case CONTACT_REQUEST_REFUSED = 'contact_request_refused';

    case JURY_INVITATION = 'jury_invitation';
    case JURY_ACCEPTED = 'jury_accepted';
    case JURY_REFUSED = 'jury_refused';
    case DEFENSE_VERIFIED = 'defense_verified';

    case PROJECT_VERIFIED = 'project_verified';
    case PROJECT_CORRECTION_REQUESTED = 'project_correction_requested';

    case COMMENT_RECEIVED = 'comment_received';
    case COMMENT_REPLY = 'comment_reply';
    case PROJECT_RATING_RECEIVED = 'project_rating_received';

    case ACCOUNT_WARNED = 'account_warned';
    case ACCOUNT_SUSPENDED = 'account_suspended';
    case ACCOUNT_BANNED = 'account_banned';

    case REPORT_RECEIVED = 'report_received';

    public function label(): string
    {
        return match ($this) {
            self::CONTACT_REQUEST_RECEIVED => 'Nouvelle demande de contact',
            self::CONTACT_REQUEST_ACCEPTED => 'Demande de contact acceptée',
            self::CONTACT_REQUEST_REFUSED => 'Demande de contact refusée',
            self::JURY_INVITATION => 'Invitation à un jury',
            self::JURY_ACCEPTED => 'Participation au jury confirmée',
            self::JURY_REFUSED => 'Invitation au jury déclinée',
            self::DEFENSE_VERIFIED => 'Soutenance vérifiée',
            self::PROJECT_VERIFIED => 'Projet vérifié',
            self::PROJECT_CORRECTION_REQUESTED => 'Correction demandée',
            self::COMMENT_RECEIVED => 'Nouveau commentaire',
            self::COMMENT_REPLY => 'Réponse à un commentaire',
            self::PROJECT_RATING_RECEIVED => 'Nouvelle évaluation',
            self::ACCOUNT_WARNED => 'Avertissement',
            self::ACCOUNT_SUSPENDED => 'Compte suspendu',
            self::ACCOUNT_BANNED => 'Compte banni',
            self::REPORT_RECEIVED => 'Nouveau signalement',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CONTACT_REQUEST_RECEIVED, self::CONTACT_REQUEST_ACCEPTED => '💼',
            self::CONTACT_REQUEST_REFUSED => '✖️',
            self::JURY_INVITATION, self::JURY_ACCEPTED, self::JURY_REFUSED, self::DEFENSE_VERIFIED => '🎓',
            self::PROJECT_VERIFIED => '✅',
            self::PROJECT_CORRECTION_REQUESTED => '⚠️',
            self::COMMENT_RECEIVED, self::COMMENT_REPLY => '💬',
            self::PROJECT_RATING_RECEIVED => '⭐',
            self::ACCOUNT_WARNED, self::ACCOUNT_SUSPENDED, self::ACCOUNT_BANNED => '🔒',
            self::REPORT_RECEIVED => '🚩',
        };
    }

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::CONTACT_REQUEST_RECEIVED, self::CONTACT_REQUEST_ACCEPTED, self::CONTACT_REQUEST_REFUSED => NotificationCategory::CONTACT,
            self::JURY_INVITATION, self::JURY_ACCEPTED, self::JURY_REFUSED, self::DEFENSE_VERIFIED => NotificationCategory::SOUTENANCE,
            self::PROJECT_VERIFIED, self::PROJECT_CORRECTION_REQUESTED => NotificationCategory::PROJET,
            self::COMMENT_RECEIVED, self::COMMENT_REPLY, self::PROJECT_RATING_RECEIVED => NotificationCategory::COMMUNAUTE,
            self::ACCOUNT_WARNED, self::ACCOUNT_SUSPENDED, self::ACCOUNT_BANNED => NotificationCategory::SECURITE,
            self::REPORT_RECEIVED => NotificationCategory::MODERATION,
        };
    }
}
