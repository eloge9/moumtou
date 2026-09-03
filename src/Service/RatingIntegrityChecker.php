<?php

namespace App\Service;

use App\Entity\Rating;
use App\Entity\User;
use App\Enum\RatingStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Détection légère des comportements anormaux sur les évaluations (cahier
 * des charges §10). Ne bloque jamais automatiquement : marque simplement
 * l'évaluation NORMAL/SUSPECT/FLAGGED pour que l'administrateur l'examine.
 */
class RatingIntegrityChecker
{
    private const BURST_WINDOW = '-10 minutes';
    private const BURST_FLAGGED_THRESHOLD = 8;
    private const BURST_SUSPECT_THRESHOLD = 4;
    private const NEW_ACCOUNT_WINDOW = '-1 hour';

    public function evaluate(User $user, EntityManagerInterface $em): RatingStatus
    {
        $recentCount = (int) $em->getRepository(Rating::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->andWhere('r.createdAt >= :since')->setParameter('since', new \DateTimeImmutable(self::BURST_WINDOW))
            ->getQuery()->getSingleScalarResult();

        if ($recentCount >= self::BURST_FLAGGED_THRESHOLD) {
            return RatingStatus::FLAGGED;
        }

        $isVeryNewAccount = $user->getCreatedAt() > new \DateTimeImmutable(self::NEW_ACCOUNT_WINDOW);
        if ($recentCount >= self::BURST_SUSPECT_THRESHOLD || $isVeryNewAccount) {
            return RatingStatus::SUSPECT;
        }

        return RatingStatus::NORMAL;
    }
}
