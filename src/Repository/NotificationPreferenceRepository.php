<?php

namespace App\Repository;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationPreference>
 */
class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    /**
     * Résout la préférence effective pour un utilisateur/catégorie, avec
     * les valeurs par défaut du cahier des charges §23/§24 lorsqu'aucune
     * ligne n'existe encore (compte jamais allé régler ses préférences).
     * La catégorie sécurité est toujours forcée active, quoi qu'il arrive.
     *
     * @return array{inApp: bool, email: bool}
     */
    public function resolve(User $user, NotificationCategory $category): array
    {
        if ($category->isMandatory()) {
            return ['inApp' => true, 'email' => true];
        }

        $preference = $this->findOneBy(['user' => $user, 'category' => $category]);
        if ($preference) {
            return ['inApp' => $preference->isInAppEnabled(), 'email' => $preference->isEmailEnabled()];
        }

        return ['inApp' => true, 'email' => $category->defaultEmailEnabled()];
    }
}
