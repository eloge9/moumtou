<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectPhoto>
 */
class ProjectPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectPhoto::class);
    }

    /**
     * Récupère en une seule requête l'image de couverture (position la plus
     * basse) de chaque projet d'une liste — cahier des charges §22 : évite
     * qu'une carte de projet dans une liste (Explorer, Recherche, profil,
     * établissement) déclenche une requête séparée par projet pour sa seule
     * vignette. Ne pagine pas, ne rejoint pas la requête principale : reste
     * sans effet sur la pagination des listes de projets.
     *
     * @param Project[] $projects
     *
     * @return array<int, ProjectPhoto> indexé par id de projet
     */
    public function findCoversForProjects(array $projects): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map(static fn (Project $p) => $p->getId(), $projects))));
        if (!$projectIds) {
            return [];
        }

        // Sous-requête corrélée : la plus petite position par projet, pour
        // ne récupérer qu'une seule photo (la couverture) par projet même
        // si plusieurs partagent la même position minimale par accident.
        $rows = $this->createQueryBuilder('photo')
            ->andWhere('photo.project IN (:projects)')->setParameter('projects', $projectIds)
            ->andWhere('photo.position = (
                SELECT MIN(p2.position) FROM App\Entity\ProjectPhoto p2 WHERE p2.project = photo.project
            )')
            ->getQuery()->getResult();

        $covers = [];
        foreach ($rows as $photo) {
            $projectId = $photo->getProject()->getId();
            // En cas d'égalité de position (ne devrait pas arriver en usage
            // normal), on garde la première rencontrée plutôt que d'écraser.
            if (!isset($covers[$projectId])) {
                $covers[$projectId] = $photo;
            }
        }

        return $covers;
    }
}
