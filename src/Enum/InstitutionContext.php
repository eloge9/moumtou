<?php

namespace App\Enum;

/**
 * Précise le contexte du rattachement d'un utilisateur à un établissement
 * (table de jonction UserInstitution) : un même compte peut être étudiant
 * dans un établissement et enseignant dans un autre, sans que cela ne
 * touche au système d'autorisation (ROLE_TALENT/ROLE_TEACHER/...), qui
 * reste inchangé et fait autorité pour les permissions.
 */
enum InstitutionContext: string
{
    case ETUDIANT = 'etudiant';
    case ENSEIGNANT = 'enseignant';
    case ANCIEN_ETUDIANT = 'ancien_etudiant';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::ETUDIANT => 'Étudiant',
            self::ENSEIGNANT => 'Enseignant',
            self::ANCIEN_ETUDIANT => 'Ancien étudiant',
            self::AUTRE => 'Autre',
        };
    }
}
