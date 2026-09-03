<?php

namespace App\Repository;

use App\Entity\Defense;
use App\Enum\DefenseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Defense>
 */
class DefenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Defense::class);
    }

    /**
     * Soutenances annoncées à venir, projet public (cahier des charges §24 :
     * « Soutenances à venir » sur la recherche vide).
     *
     * @return Defense[]
     */
    public function findUpcoming(int $limit): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.project', 'p')->addSelect('p')
            ->join('p.owner', 'owner')->addSelect('owner')
            ->andWhere('d.status = :status')->setParameter('status', DefenseStatus::ANNONCEE)
            ->andWhere('d.date >= :today')->setParameter('today', new \DateTimeImmutable('today'))
            ->andWhere('p.status IN (:statuses)')->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES)
            ->orderBy('d.date', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
