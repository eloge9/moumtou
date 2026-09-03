<?php

namespace App\Repository;

use App\Entity\Institution;
use App\Entity\Project;
use App\Enum\DefenseStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Search\InstitutionSearchCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Institution>
 */
class InstitutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Institution::class);
    }

    /**
     * Annuaire public (nouvelle fonctionnalité « Établissements ») : seuls
     * les établissements actifs sont listés — mêmes règles de visibilité
     * que celles déjà utilisées pour les listes de sélection ({@see
     * \App\Controller\Admin\InstitutionController}).
     *
     * @return array{items: Institution[], total: int}
     */
    public function search(InstitutionSearchCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.active = true');

        if ($criteria->query) {
            $qb->andWhere('i.name LIKE :recherche')->setParameter('recherche', '%'.$criteria->query.'%');
        }
        if ($criteria->country) {
            $qb->andWhere('i.country = :country')->setParameter('country', $criteria->country);
        }
        if ($criteria->city) {
            $qb->andWhere('i.city LIKE :city')->setParameter('city', '%'.$criteria->city.'%');
        }

        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();

        $perPage = min(max(1, $criteria->perPage), InstitutionSearchCriteria::MAX_PER_PAGE);
        $items = $qb->orderBy('i.name', 'ASC')
            ->setFirstResult((max(1, $criteria->page) - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return string[]
     */
    public function distinctCountries(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT i.country')
            ->andWhere('i.active = true')
            ->andWhere('i.country IS NOT NULL')
            ->orderBy('i.country', 'ASC')
            ->getQuery()->getScalarResult();

        return array_column($rows, 'country');
    }

    /**
     * Compteurs "projets publiés / soutenances" pour un lot d'établissements
     * en une seule requête groupée (annuaire — cahier §28 : jamais une
     * requête par carte, pour éviter le N+1).
     *
     * @param int[] $institutionIds
     *
     * @return array<int, array{projects: int, defenses: int}>
     */
    public function countProjectsAndDefensesByInstitutions(array $institutionIds): array
    {
        if (!$institutionIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getRepository(Project::class)->createQueryBuilder('p')
            ->select(
                'IDENTITY(p.institution) AS institutionId',
                'COUNT(DISTINCT p.id) AS projects',
                'SUM(CASE WHEN p.type = :soutenance THEN 1 ELSE 0 END) AS defenses'
            )
            ->andWhere('p.institution IN (:institutionIds)')->setParameter('institutionIds', $institutionIds)
            ->andWhere('p.status IN (:publicStatuses)')->setParameter('publicStatuses', ProjectRepository::PUBLIC_STATUSES)
            ->setParameter('soutenance', ProjectType::SOUTENANCE)
            ->groupBy('p.institution')
            ->getQuery()->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['institutionId']] = [
                'projects' => (int) $row['projects'],
                'defenses' => (int) $row['defenses'],
            ];
        }

        return $counts;
    }

    /**
     * Statistiques détaillées d'un seul établissement (page détail). À la
     * différence de {@see countProjectsAndDefensesByInstitutions()}
     * (annuaire, plusieurs établissements à la fois), cette méthode calcule
     * aussi les soutenances vérifiées, réservée à un seul établissement à
     * chaque appel — pas de risque de N+1 puisqu'appelée une fois par page.
     *
     * @return array{projects: int, verifiedProjects: int, defenses: int, verifiedDefenses: int}
     */
    public function computeStats(Institution $institution): array
    {
        $row = $this->getEntityManager()->getRepository(Project::class)->createQueryBuilder('p')
            ->select(
                'COUNT(DISTINCT p.id) AS projects',
                'SUM(CASE WHEN p.status = :verifie THEN 1 ELSE 0 END) AS verifiedProjects',
                'SUM(CASE WHEN p.type = :soutenance THEN 1 ELSE 0 END) AS defenses',
                'SUM(CASE WHEN d.status = :defenseVerifiee THEN 1 ELSE 0 END) AS verifiedDefenses'
            )
            ->leftJoin('p.defense', 'd')
            ->andWhere('p.institution = :institution')->setParameter('institution', $institution)
            ->andWhere('p.status IN (:publicStatuses)')->setParameter('publicStatuses', ProjectRepository::PUBLIC_STATUSES)
            ->setParameter('verifie', ProjectStatus::VERIFIE)
            ->setParameter('soutenance', ProjectType::SOUTENANCE)
            ->setParameter('defenseVerifiee', DefenseStatus::VERIFIEE)
            ->getQuery()->getOneOrNullResult();

        return [
            'projects' => (int) ($row['projects'] ?? 0),
            'verifiedProjects' => (int) ($row['verifiedProjects'] ?? 0),
            'defenses' => (int) ($row['defenses'] ?? 0),
            'verifiedDefenses' => (int) ($row['verifiedDefenses'] ?? 0),
        ];
    }
}
