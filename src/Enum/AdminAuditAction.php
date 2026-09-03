<?php

namespace App\Enum;

/**
 * Vocabulaire contrôlé des actions journalisées (cahier des charges —
 * FONCTIONNALITÉ 9 §40/§41). Couvre les domaines réellement administrés
 * par MOUMTOU ; à étendre au même endroit si de nouvelles actions admin
 * apparaissent.
 */
enum AdminAuditAction: string
{
    case USER_WARNED = 'user_warned';
    case USER_SUSPENDED = 'user_suspended';
    case USER_UNSUSPENDED = 'user_unsuspended';
    case USER_BANNED = 'user_banned';

    case PROJECT_PUBLISHED = 'project_published';
    case PROJECT_VERIFIED = 'project_verified';
    case PROJECT_UNVERIFIED = 'project_unverified';
    case PROJECT_HIDDEN = 'project_hidden';
    case PROJECT_UNPUBLISHED = 'project_unpublished';
    case PROJECT_DELETED = 'project_deleted';
    case CORRECTION_REQUESTED = 'correction_requested';

    case REPORT_RESOLVED = 'report_resolved';
    case REPORT_REJECTED = 'report_rejected';

    case COMMENT_HIDDEN = 'comment_hidden';
    case COMMENT_DELETED = 'comment_deleted';
    case COMMENT_RESTORED = 'comment_restored';

    case INSTITUTION_CREATED = 'institution_created';
    case INSTITUTION_UPDATED = 'institution_updated';
    case INSTITUTION_VERIFIED = 'institution_verified';
    case INSTITUTION_UNVERIFIED = 'institution_unverified';
    case INSTITUTION_DEACTIVATED = 'institution_deactivated';
    case INSTITUTION_REACTIVATED = 'institution_reactivated';
    case INSTITUTION_DELETED = 'institution_deleted';

    case DOMAIN_CREATED = 'domain_created';
    case DOMAIN_RENAMED = 'domain_renamed';
    case DOMAIN_DEACTIVATED = 'domain_deactivated';
    case DOMAIN_REACTIVATED = 'domain_reactivated';
    case DOMAIN_DELETED = 'domain_deleted';
    case MENTION_CREATED = 'mention_created';
    case MENTION_RENAMED = 'mention_renamed';
    case MENTION_DEACTIVATED = 'mention_deactivated';
    case MENTION_REACTIVATED = 'mention_reactivated';
    case MENTION_DELETED = 'mention_deleted';
    case SPECIALTY_CREATED = 'specialty_created';
    case SPECIALTY_RENAMED = 'specialty_renamed';
    case SPECIALTY_DEACTIVATED = 'specialty_deactivated';
    case SPECIALTY_REACTIVATED = 'specialty_reactivated';
    case SPECIALTY_DELETED = 'specialty_deleted';

    case TECHNOLOGY_CREATED = 'technology_created';
    case TECHNOLOGY_RENAMED = 'technology_renamed';
    case TECHNOLOGY_MERGED = 'technology_merged';
    case TECHNOLOGY_DELETED = 'technology_deleted';
    case SKILL_CREATED = 'skill_created';
    case SKILL_DELETED = 'skill_deleted';

    case DEFENSE_RESULT_VALIDATED = 'defense_result_validated';

    case USER_ROLE_CHANGED = 'user_role_changed';

    public function label(): string
    {
        return match ($this) {
            self::USER_WARNED => 'Utilisateur averti',
            self::USER_SUSPENDED => 'Utilisateur suspendu',
            self::USER_UNSUSPENDED => 'Utilisateur réactivé',
            self::USER_BANNED => 'Utilisateur banni',
            self::PROJECT_PUBLISHED => 'Projet publié',
            self::PROJECT_VERIFIED => 'Projet vérifié',
            self::PROJECT_UNVERIFIED => 'Vérification retirée',
            self::PROJECT_HIDDEN => 'Projet masqué/supprimé',
            self::PROJECT_UNPUBLISHED => 'Projet dépublié',
            self::PROJECT_DELETED => 'Projet supprimé',
            self::CORRECTION_REQUESTED => 'Correction demandée',
            self::REPORT_RESOLVED => 'Signalement traité',
            self::REPORT_REJECTED => 'Signalement rejeté',
            self::COMMENT_HIDDEN => 'Commentaire masqué',
            self::COMMENT_DELETED => 'Commentaire supprimé',
            self::COMMENT_RESTORED => 'Commentaire restauré',
            self::INSTITUTION_CREATED => 'Établissement créé',
            self::INSTITUTION_UPDATED => 'Établissement modifié',
            self::INSTITUTION_VERIFIED => 'Établissement vérifié',
            self::INSTITUTION_UNVERIFIED => 'Vérification établissement retirée',
            self::INSTITUTION_DEACTIVATED => 'Établissement désactivé',
            self::INSTITUTION_REACTIVATED => 'Établissement réactivé',
            self::INSTITUTION_DELETED => 'Établissement supprimé',
            self::DOMAIN_CREATED => 'Domaine créé',
            self::DOMAIN_RENAMED => 'Domaine renommé',
            self::DOMAIN_DEACTIVATED => 'Domaine désactivé',
            self::DOMAIN_REACTIVATED => 'Domaine réactivé',
            self::DOMAIN_DELETED => 'Domaine supprimé',
            self::MENTION_CREATED => 'Mention créée',
            self::MENTION_RENAMED => 'Mention renommée',
            self::MENTION_DEACTIVATED => 'Mention désactivée',
            self::MENTION_REACTIVATED => 'Mention réactivée',
            self::MENTION_DELETED => 'Mention supprimée',
            self::SPECIALTY_CREATED => 'Spécialité créée',
            self::SPECIALTY_RENAMED => 'Spécialité renommée',
            self::SPECIALTY_DEACTIVATED => 'Spécialité désactivée',
            self::SPECIALTY_REACTIVATED => 'Spécialité réactivée',
            self::SPECIALTY_DELETED => 'Spécialité supprimée',
            self::TECHNOLOGY_CREATED => 'Technologie créée',
            self::TECHNOLOGY_RENAMED => 'Technologie renommée',
            self::TECHNOLOGY_MERGED => 'Technologies fusionnées',
            self::TECHNOLOGY_DELETED => 'Technologie supprimée',
            self::SKILL_CREATED => 'Compétence créée',
            self::SKILL_DELETED => 'Compétence supprimée',
            self::DEFENSE_RESULT_VALIDATED => 'Résultat de soutenance validé (admin)',
            self::USER_ROLE_CHANGED => 'Rôle utilisateur modifié',
        };
    }
}
