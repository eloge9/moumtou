<?php

namespace App\Repository;

use App\Entity\Project;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Search\ProjectSearchCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    /** Statuts visibles publiquement (cahier des charges §30). */
    public const PUBLIC_STATUSES = [ProjectStatus::PUBLIE, ProjectStatus::VERIFIE];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * @return array{items: Project[], total: int}
     */
    public function search(ProjectSearchCriteria $criteria): array
    {
        $qb = $this->baseQuery($criteria);

        $total = (clone $qb)
            ->select('COUNT(DISTINCT p.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $qb->select('p')->distinct()
            ->setFirstResult(($criteria->page - 1) * $criteria->perPage)
            ->setMaxResults($criteria->perPage);

        $this->applySort($qb, $criteria->sort);

        return [
            'items' => $qb->getQuery()->getResult(),
            'total' => (int) $total,
        ];
    }

    /**
     * Nombre de projets publics par type, pour les compteurs de la barre de filtres.
     *
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.type AS type, COUNT(p.id) AS total')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', self::PUBLIC_STATUSES)
            ->groupBy('p.type')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type'] instanceof ProjectType ? $row['type']->value : $row['type']] = (int) $row['total'];
        }

        return $counts;
    }

    private function baseQuery(ProjectSearchCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.owner', 'owner')->addSelect('owner')
            ->leftJoin('p.institution', 'institution')->addSelect('institution')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', self::PUBLIC_STATUSES);

        if ($criteria->query) {
            $qb->andWhere($qb->expr()->orX(
                'p.name LIKE :recherche',
                'owner.firstName LIKE :recherche',
                'owner.lastName LIKE :recherche',
                $qb->expr()->in('p.id', $this->createQueryBuilder('p3')
                    ->select('p3.id')
                    ->join('p3.technologies', 't3')
                    ->where('t3.name LIKE :recherche')
                    ->getDQL()),
            ))->setParameter('recherche', '%'.$criteria->query.'%');
        }
        if ($criteria->types) {
            $qb->andWhere('p.type IN (:types)')->setParameter('types', $criteria->types);
        }
        if ($criteria->domainId) {
            $qb->andWhere('p.domain = :domainId')->setParameter('domainId', $criteria->domainId);
        }
        if ($criteria->mentionId) {
            $qb->andWhere('p.mention = :mentionId')->setParameter('mentionId', $criteria->mentionId);
        }
        if ($criteria->specialtyId) {
            $qb->andWhere('p.specialty = :specialtyId')->setParameter('specialtyId', $criteria->specialtyId);
        }
        if ($criteria->institutionId) {
            $qb->andWhere('institution.id = :institutionId')->setParameter('institutionId', $criteria->institutionId);
        }
        if ($criteria->country) {
            $qb->andWhere('institution.country = :country')->setParameter('country', $criteria->country);
        }
        if ($criteria->city) {
            $qb->andWhere('institution.city LIKE :city')->setParameter('city', '%'.$criteria->city.'%');
        }
        if ($criteria->yearMin) {
            $qb->andWhere('p.realizationDate >= :yearMin')->setParameter('yearMin', sprintf('%d-01-01', $criteria->yearMin));
        }
        if ($criteria->statuses) {
            $qb->andWhere('p.status IN (:narrowedStatuses)')->setParameter('narrowedStatuses', $criteria->statuses);
        }
        if ($criteria->technologyIds) {
            // Sous-requête : le projet doit avoir AU MOINS une des technologies cochées.
            $qb->andWhere($qb->expr()->in('p.id', $this->createQueryBuilder('p2')
                ->select('p2.id')
                ->join('p2.technologies', 't2')
                ->where('t2.id IN (:technologyIds)')
                ->getDQL()))
                ->setParameter('technologyIds', $criteria->technologyIds);
        }

        return $qb;
    }

    private function applySort(QueryBuilder $qb, string $sort): void
    {
        match ($sort) {
            ProjectSearchCriteria::SORT_RATING => $qb->addOrderBy('p.ratingAverage', 'DESC'),
            ProjectSearchCriteria::SORT_VIEWS => $qb->addOrderBy('p.viewsCount', 'DESC'),
            default => $qb->addOrderBy('p.publishedAt', 'DESC'),
        };
        $qb->addOrderBy('p.id', 'DESC');
    }
}
