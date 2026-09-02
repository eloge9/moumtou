<?php

namespace App\Enum;

/**
 * Profil choisi à l'inscription (cahier des charges §4.2, §4.4, §4.5).
 * Un compte a un seul profil "métier" à la fois — cf. la structure
 * USER → TALENT/TEACHER/RECRUITER du cahier des charges §37. L'étudiant
 * n'est pas un profil séparé : c'est un TALENT qui publie un projet de type
 * soutenance (§4.3), donc il n'a pas d'entrée ici.
 */
enum AccountType: string
{
    case TALENT = 'talent';
    case TEACHER = 'teacher';
    case RECRUITER = 'recruiter';

    public function label(): string
    {
        return match ($this) {
            self::TALENT => 'Talent / Porteur de projet',
            self::TEACHER => 'Enseignant / Membre du jury',
            self::RECRUITER => 'Recruteur',
        };
    }

    public function role(): string
    {
        return match ($this) {
            self::TALENT => 'ROLE_TALENT',
            self::TEACHER => 'ROLE_TEACHER',
            self::RECRUITER => 'ROLE_RECRUITER',
        };
    }
}
