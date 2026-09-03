<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Technology;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Technology>
 */
class TechnologyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Technology::class);
    }

    /**
     * Technologies les plus utilisées par les projets publics (cahier des
     * charges §24 : « Technologies populaires » sur la recherche vide).
     * La relation Project → Technology n'a pas de côté inverse mappé, la
     * requête part donc de Project (seul côté propriétaire de la relation) ;
     * sélection purement scalaire (id/nom), Doctrine n'autorisant pas de
     * réhydrater une entité jointe (non racine) mêlée à un GROUP BY agrégé.
     *
     * @return array<int, array{id: int, name: string, total: int}>
     */
    public function findMostUsed(int $limit): array
    {
        $rows = $this->getEntityManager()->getRepository(Project::class)->createQueryBuilder('p')
            ->select('t.id AS id, t.name AS name, COUNT(DISTINCT p.id) AS total')
            ->join('p.technologies', 't')
            ->andWhere('p.status IN (:statuses)')->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES)
            ->groupBy('t.id', 't.name')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn ($row) => ['id' => (int) $row['id'], 'name' => $row['name'], 'total' => (int) $row['total']], $rows);
    }
}
