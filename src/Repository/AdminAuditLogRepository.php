<?php

namespace App\Repository;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAuditLog>
 */
class AdminAuditLogRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /**
     * @return array{items: AdminAuditLog[], total: int}
     */
    public function search(?User $admin, ?AdminAuditAction $action, ?string $targetType, ?string $dateFrom, ?string $dateTo, int $page): array
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.admin', 'a')->addSelect('a')
            ->orderBy('l.createdAt', 'DESC');

        if ($admin) {
            $qb->andWhere('l.admin = :admin')->setParameter('admin', $admin);
        }
        if ($action) {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        }
        if ($targetType) {
            $qb->andWhere('l.targetType = :targetType')->setParameter('targetType', $targetType);
        }
        if ($dateFrom) {
            $qb->andWhere('l.createdAt >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom.' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('l.createdAt <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo.' 23:59:59'));
        }

        $total = (int) (clone $qb)->select('COUNT(l.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult((max(1, $page) - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return string[] les valeurs distinctes de targetType déjà journalisées, pour le filtre.
     */
    public function distinctTargetTypes(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.targetType AS targetType')
            ->andWhere('l.targetType IS NOT NULL')
            ->orderBy('l.targetType', 'ASC')
            ->getQuery()->getScalarResult();

        return array_column($rows, 'targetType');
    }
}
