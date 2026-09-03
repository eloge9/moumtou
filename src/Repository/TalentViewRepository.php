<?php

namespace App\Repository;

use App\Entity\TalentView;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TalentView>
 */
class TalentViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TalentView::class);
    }

    public function countDistinctTalents(User $recruiter): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.talent)')
            ->andWhere('v.recruiter = :recruiter')->setParameter('recruiter', $recruiter)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Historique des talents consultés (cahier §20), le plus récent par
     * talent en tête, dédupliqué et paginé.
     *
     * @return TalentView[]
     */
    public function findRecentDistinct(User $recruiter, int $limit): array
    {
        // Sous-requête : dernière consultation par talent, pour éviter
        // qu'un talent revu plusieurs fois n'écrase l'historique.
        $latestPerTalent = $this->createQueryBuilder('v2')
            ->select('MAX(v2.id)')
            ->andWhere('v2.recruiter = :recruiter')
            ->groupBy('v2.talent')
            ->getDQL();

        return $this->createQueryBuilder('v')
            ->join('v.talent', 't')->addSelect('t')
            ->andWhere('v.recruiter = :recruiter')
            ->andWhere($this->getEntityManager()->createQueryBuilder()->expr()->in('v.id', $latestPerTalent))
            ->setParameter('recruiter', $recruiter)
            ->orderBy('v.viewedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
