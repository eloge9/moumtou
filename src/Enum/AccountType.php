<?php

namespace App\Enum;

/**
 * Profil choisi à l'inscription. Chaque compte porte toujours ROLE_TALENT
 * comme rôle de base (inscription/rôles multiples §2/§9/§21) ; ce choix
 * détermine uniquement le rôle ADDITIONNEL demandé au départ — TALENT seul
 * n'en ajoute aucun. Le rôle choisi ici n'est activé qu'une fois le
 * formulaire dédié à ce rôle complété (§12), jamais immédiatement.
 */
enum AccountType: string
{
    case TALENT = 'talent';
    case STUDENT = 'student';
    case TEACHER = 'teacher';
    case RECRUITER = 'recruiter';

    public function label(): string
    {
        return match ($this) {
            self::TALENT => 'Talent / Porteur de projet',
            self::STUDENT => 'Étudiant',
            self::TEACHER => 'Enseignant / Membre du jury',
            self::RECRUITER => 'Recruteur',
        };
    }

    /** Rôle additionnel demandé, ou null si aucun (choix TALENT). */
    public function role(): ?string
    {
        return match ($this) {
            self::TALENT => null,
            self::STUDENT => 'ROLE_STUDENT',
            self::TEACHER => 'ROLE_TEACHER',
            self::RECRUITER => 'ROLE_RECRUITER',
        };
    }
}
