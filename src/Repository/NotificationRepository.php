<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnread(User $recipient): int
    {
        return $this->count(['recipient' => $recipient, 'isRead' => false]);
    }

    /**
     * @return Notification[]
     */
    public function findRecent(User $recipient, int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')->setParameter('recipient', $recipient)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return array{items: Notification[], total: int}
     */
    public function findPage(User $recipient, int $page): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')->setParameter('recipient', $recipient);

        $total = (int) (clone $qb)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->orderBy('n.createdAt', 'DESC')
            ->setFirstResult((max(1, $page) - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function markAllAsRead(User $recipient): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', ':true')
            ->set('n.readAt', ':now')
            ->andWhere('n.recipient = :recipient')->setParameter('recipient', $recipient)
            ->andWhere('n.isRead = :false')
            ->setParameter('true', true)
            ->setParameter('false', false)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->execute();
    }
}
