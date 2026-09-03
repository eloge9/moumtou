<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\ProjectStatus;
use App\Search\TalentSearchCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Met à jour (rehash) le mot de passe automatiquement au besoin.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Recherche de talents (cahier des charges §4/§32) : un « talent » est un
     * compte ayant publié au moins un projet visible publiquement — même
     * définition que {@see \App\Controller\RecruiterController} déjà en
     * place, désormais partagée avec la recherche générale.
     *
     * @return array{items: User[], total: int}
     */
    public function search(TalentSearchCriteria $criteria): array
    {
        $qb = $this->baseQuery($criteria);

        $total = (clone $qb)
            ->select('COUNT(DISTINCT u.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $perPage = min(max(1, $criteria->perPage), TalentSearchCriteria::MAX_PER_PAGE);
        $qb->select('u')->distinct()
            ->setFirstResult((max(1, $criteria->page) - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->applySort($qb, $criteria->sort, $criteria->query);

        return [
            'items' => $qb->getQuery()->getResult(),
            'total' => (int) $total,
        ];
    }

    private function baseQuery(TalentSearchCriteria $criteria): QueryBuilder
    {
        $publicOwnerIdsDql = $this->getEntityManager()->getRepository(Project::class)->createQueryBuilder('pOwner')
            ->select('IDENTITY(pOwner.owner)')
            ->where('pOwner.status IN (:publicStatuses)')
            ->getDQL();

        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.institution', 'institution')->addSelect('institution')
            ->andWhere($this->getEntityManager()->createQueryBuilder()->expr()->in('u.id', $publicOwnerIdsDql))
            ->setParameter('publicStatuses', ProjectRepository::PUBLIC_STATUSES);

        if ($criteria->query) {
            $qb->andWhere($qb->expr()->orX(
                'u.firstName LIKE :recherche',
                'u.lastName LIKE :recherche',
                'u.bio LIKE :recherche',
                "CONCAT(u.firstName, ' ', u.lastName) LIKE :recherche",
                "CONCAT(u.lastName, ' ', u.firstName) LIKE :recherche",
            ))->setParameter('recherche', '%'.$criteria->query.'%');
        }
        if ($criteria->country) {
            $qb->andWhere('u.country = :country')->setParameter('country', $criteria->country);
        }
        if ($criteria->city) {
            $qb->andWhere('u.city LIKE :city')->setParameter('city', '%'.$criteria->city.'%');
        }
        if ($criteria->institutionId) {
            $qb->andWhere('institution.id = :institutionId')->setParameter('institutionId', $criteria->institutionId);
        }
        if ($criteria->availability) {
            $qb->andWhere('u.availability = :availability')->setParameter('availability', $criteria->availability);
        }
        if ($criteria->skillIds) {
            $qb->andWhere($qb->expr()->in('u.id', $this->createQueryBuilder('uSkill')
                ->select('uSkill.id')
                ->join('uSkill.skills', 'skill')
                ->where('skill.id IN (:skillIds)')
                ->getDQL()))
                ->setParameter('skillIds', $criteria->skillIds);
        }
        if ($criteria->technologyIds) {
            if (TalentSearchCriteria::TECH_MODE_ALL === $criteria->techMode) {
                foreach (array_values($criteria->technologyIds) as $index => $technologyId) {
                    $alias = 'tAll'.$index;
                    $qb->andWhere($qb->expr()->in('u.id', $this->createQueryBuilder($alias.'_u')
                        ->select($alias.'_u.id')
                        ->join($alias.'_u.technologies', $alias)
                        ->where($alias.'.id = :'.$alias)
                        ->getDQL()))
                        ->setParameter($alias, $technologyId);
                }
            } else {
                $qb->andWhere($qb->expr()->in('u.id', $this->createQueryBuilder('uTech')
                    ->select('uTech.id')
                    ->join('uTech.technologies', 'tech')
                    ->where('tech.id IN (:technologyIds)')
                    ->getDQL()))
                    ->setParameter('technologyIds', $criteria->technologyIds);
            }
        }

        // Domaine / mention / spécialité / type de projet / année : dérivés
        // des projets publics du talent (le talent lui-même ne porte pas ces
        // attributs, seuls ses projets les portent — cahier §4/§11).
        if ($criteria->domainId || $criteria->mentionId || $criteria->specialtyId || $criteria->projectTypes || $criteria->yearMin) {
            $projectMatch = $this->getEntityManager()->getRepository(Project::class)->createQueryBuilder('pMatch')
                ->select('1')
                ->where('pMatch.status IN (:publicStatusesMatch)')
                ->andWhere('IDENTITY(pMatch.owner) = u.id');

            if ($criteria->domainId) {
                $projectMatch->andWhere('pMatch.domain = :domainId');
            }
            if ($criteria->mentionId) {
                $projectMatch->andWhere('pMatch.mention = :mentionId');
            }
            if ($criteria->specialtyId) {
                $projectMatch->andWhere('pMatch.specialty = :specialtyId');
            }
            if ($criteria->projectTypes) {
                $projectMatch->andWhere('pMatch.type IN (:projectTypes)');
            }
            if ($criteria->yearMin) {
                $projectMatch->andWhere('pMatch.realizationDate >= :yearMin');
            }

            $qb->andWhere($qb->expr()->exists($projectMatch->getDQL()))
                ->setParameter('publicStatusesMatch', ProjectRepository::PUBLIC_STATUSES);
            if ($criteria->domainId) {
                $qb->setParameter('domainId', $criteria->domainId);
            }
            if ($criteria->mentionId) {
                $qb->setParameter('mentionId', $criteria->mentionId);
            }
            if ($criteria->specialtyId) {
                $qb->setParameter('specialtyId', $criteria->specialtyId);
            }
            if ($criteria->projectTypes) {
                $qb->setParameter('projectTypes', $criteria->projectTypes);
            }
            if ($criteria->yearMin) {
                $qb->setParameter('yearMin', sprintf('%d-01-01', $criteria->yearMin));
            }
        }

        return $qb;
    }

    private function applySort(QueryBuilder $qb, string $sort, ?string $query): void
    {
        if (TalentSearchCriteria::SORT_RELEVANCE === $sort && $query) {
            $qb->addSelect(
                "(CASE WHEN CONCAT(u.firstName, ' ', u.lastName) LIKE :exactQ THEN 0 ".
                'WHEN u.lastName LIKE :startQ OR u.firstName LIKE :startQ THEN 1 ELSE 2 END) AS HIDDEN pertinence'
            )
                ->setParameter('exactQ', $query)
                ->setParameter('startQ', $query.'%')
                ->addOrderBy('pertinence', 'ASC');
        }

        match ($sort) {
            TalentSearchCriteria::SORT_NAME => $qb->addOrderBy('u.lastName', 'ASC')->addOrderBy('u.firstName', 'ASC'),
            TalentSearchCriteria::SORT_RECENT => $qb->addOrderBy('u.createdAt', 'DESC'),
            default => $qb->addOrderBy('u.createdAt', 'DESC'),
        };
        $qb->addOrderBy('u.id', 'DESC');
    }

    /**
     * Compteurs "projets / projets vérifiés / soutenances vérifiées" pour un
     * lot de talents en une seule requête groupée (cahier — FONCTIONNALITÉ 7
     * §8/§31 : jamais une requête par talent, pour éviter le N+1).
     *
     * @param int[] $talentIds
     *
     * @return array<int, array{total: int, verified: int, verifiedDefenses: int}>
     */
    public function countProjectsByTalents(array $talentIds): array
    {
        if (!$talentIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getRepository(Project::class)->createQueryBuilder('p')
            ->select(
                'IDENTITY(p.owner) AS ownerId',
                'COUNT(DISTINCT p.id) AS total',
                'SUM(CASE WHEN p.status = :verifie THEN 1 ELSE 0 END) AS verified',
                'SUM(CASE WHEN d.status = :defenseVerifiee THEN 1 ELSE 0 END) AS verifiedDefenses'
            )
            ->leftJoin('p.defense', 'd')
            ->andWhere('p.owner IN (:talentIds)')->setParameter('talentIds', $talentIds)
            ->andWhere('p.status IN (:publicStatuses)')->setParameter('publicStatuses', ProjectRepository::PUBLIC_STATUSES)
            ->setParameter('verifie', ProjectStatus::VERIFIE)
            ->setParameter('defenseVerifiee', DefenseStatus::VERIFIEE)
            ->groupBy('p.owner')
            ->getQuery()->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['ownerId']] = [
                'total' => (int) $row['total'],
                'verified' => (int) $row['verified'],
                'verifiedDefenses' => (int) $row['verifiedDefenses'],
            ];
        }

        return $counts;
    }

    /**
     * Nombre de talents publiquement associés à un établissement (page
     * établissement, onglet Talents) : mêmes règles que la recherche de
     * talents — un compte ayant publié au moins un projet public,
     * rattaché à cet établissement comme établissement principal.
     */
    public function countByInstitution(int $institutionId): int
    {
        $publicOwnerIdsDql = $this->getEntityManager()->getRepository(Project::class)->createQueryBuilder('pOwner')
            ->select('IDENTITY(pOwner.owner)')
            ->where('pOwner.status IN (:publicStatuses)')
            ->getDQL();

        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(DISTINCT u.id)')
            ->andWhere($this->getEntityManager()->createQueryBuilder()->expr()->in('u.id', $publicOwnerIdsDql))
            ->andWhere('u.institution = :institutionId')
            ->setParameter('publicStatuses', ProjectRepository::PUBLIC_STATUSES)
            ->setParameter('institutionId', $institutionId)
            ->getQuery()->getSingleScalarResult();
    }
}
