<?php

namespace App\Repository;

use App\Entity\VerificationRequest;
use App\Enum\ReportTargetType;
use App\Enum\VerificationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VerificationRequest>
 */
class VerificationRequestRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VerificationRequest::class);
    }

    /**
     * La demande la plus récente pour une cible donnée (ouverte ou non) —
     * sert à la fois à afficher le statut courant et à empêcher les doublons.
     */
    public function findLatestForTarget(ReportTargetType $targetType, int $targetId): ?VerificationRequest
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.targetType = :type')->setParameter('type', $targetType)
            ->andWhere('v.targetId = :id')->setParameter('id', $targetId)
            ->orderBy('v.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * @return array{items: VerificationRequest[], total: int}
     */
    public function search(
        ?ReportTargetType $targetType,
        ?VerificationStatus $status,
        ?string $author,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $domainId,
        ?int $institutionId,
        int $page,
    ): array {
        $qb = $this->createQueryBuilder('v')
            ->join('v.requester', 'r')->addSelect('r')
            ->orderBy('v.createdAt', 'DESC');

        if ($targetType) {
            $qb->andWhere('v.targetType = :targetType')->setParameter('targetType', $targetType);
        }
        if ($status) {
            $qb->andWhere('v.status = :status')->setParameter('status', $status);
        }
        if ($author) {
            $qb->andWhere('r.firstName LIKE :author OR r.lastName LIKE :author OR r.email LIKE :author')
                ->setParameter('author', '%'.$author.'%');
        }
        if ($dateFrom) {
            $qb->andWhere('v.createdAt >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom.' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('v.createdAt <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo.' 23:59:59'));
        }
        // Filtres spécifiques aux projets (cahier des charges §10) : la
        // demande de vérification étant polymorphe, on résout d'abord les
        // ids de projets concernés plutôt que de joindre Project directement.
        if ($domainId) {
            $qb->andWhere('v.targetType = :typeProjet')->setParameter('typeProjet', ReportTargetType::PROJECT)
                ->andWhere('v.targetId IN (SELECT p1.id FROM App\Entity\Project p1 WHERE p1.domain = :domainId)')
                ->setParameter('domainId', $domainId);
        }
        if ($institutionId) {
            $qb->andWhere('v.targetType = :typeProjet')->setParameter('typeProjet', ReportTargetType::PROJECT)
                ->andWhere('v.targetId IN (SELECT p2.id FROM App\Entity\Project p2 WHERE p2.institution = :institutionId)')
                ->setParameter('institutionId', $institutionId);
        }

        $total = (int) (clone $qb)->select('COUNT(v.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult((max(1, $page) - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.status IN (:statuses)')
            ->setParameter('statuses', [VerificationStatus::EN_ATTENTE, VerificationStatus::EN_VERIFICATION])
            ->getQuery()->getSingleScalarResult();
    }
}
