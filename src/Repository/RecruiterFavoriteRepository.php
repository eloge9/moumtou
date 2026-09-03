<?php

namespace App\Repository;

use App\Entity\RecruiterFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecruiterFavorite>
 */
class RecruiterFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecruiterFavorite::class);
    }

    public function isFavorite(User $recruiter, User $talent): bool
    {
        return null !== $this->findOneBy(['recruiter' => $recruiter, 'talent' => $talent]);
    }

    /**
     * @return int[] identifiants des talents déjà enregistrés par ce recruteur
     */
    public function favoriteTalentIds(User $recruiter): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.talent) AS talentId')
            ->andWhere('f.recruiter = :recruiter')->setParameter('recruiter', $recruiter)
            ->getQuery()->getScalarResult();

        return array_map('intval', array_column($rows, 'talentId'));
    }
}
