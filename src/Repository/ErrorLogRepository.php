<?php

namespace App\Repository;

use App\Entity\ErrorLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErrorLog>
 */
class ErrorLogRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErrorLog::class);
    }

    /**
     * Agrégats pour le tableau de bord (cahier §25) : COUNT/GROUP BY
     * indexés, jamais un chargement de toutes les lignes en mémoire — reste
     * rapide même avec un historique volumineux.
     *
     * @return array{today: int, last24h: int, last7d: int, critical24h: int}
     */
    public function summary(): array
    {
        $now = new \DateTimeImmutable();
        $todayStart = $now->setTime(0, 0);
        $since24h = $now->modify('-24 hours');
        $since7d = $now->modify('-7 days');

        return [
            'today' => $this->countSince($todayStart),
            'last24h' => $this->countSince($since24h),
            'last7d' => $this->countSince($since7d),
            'critical24h' => $this->countSince($since24h, 'critical'),
        ];
    }

    private function countSince(\DateTimeImmutable $since, ?string $level = null): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.createdAt >= :since')->setParameter('since', $since);
        if ($level) {
            $qb->andWhere('e.level = :level')->setParameter('level', $level);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<int, array{statusCode: int, total: int}>
     */
    public function countByStatusCodeSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.statusCode AS statusCode, COUNT(e.id) AS total')
            ->andWhere('e.createdAt >= :since')->setParameter('since', $since)
            ->groupBy('e.statusCode')
            ->orderBy('total', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * @return array<int, array{path: int, total: int}>
     */
    public function mostProblematicPathsSince(\DateTimeImmutable $since, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.path AS path, COUNT(e.id) AS total')
            ->andWhere('e.createdAt >= :since')->setParameter('since', $since)
            ->groupBy('e.path')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return array{items: ErrorLog[], total: int}
     */
    public function search(?int $statusCode, ?string $level, int $page): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')->addSelect('u')
            ->orderBy('e.createdAt', 'DESC');

        if ($statusCode) {
            $qb->andWhere('e.statusCode = :statusCode')->setParameter('statusCode', $statusCode);
        }
        if ($level) {
            $qb->andWhere('e.level = :level')->setParameter('level', $level);
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult((max(1, $page) - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
