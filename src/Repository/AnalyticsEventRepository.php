<?php

namespace App\Repository;

use App\Entity\AnalyticsEvent;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\AnalyticsEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsEvent>
 */
class AnalyticsEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsEvent::class);
    }

    /**
     * Anti-abus (cahier §6) : vrai s'il existe déjà une vue du même visiteur
     * pour ce projet dans la fenêtre récente — dans ce cas, on n'enregistre
     * pas un nouvel événement.
     */
    public function hasRecentView(Project $project, ?User $user, string $visitorHash, \DateTimeImmutable $since): bool
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.project = :project')->setParameter('project', $project)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->andWhere('e.createdAt >= :since')->setParameter('since', $since);

        if ($user) {
            $qb->andWhere('e.user = :user')->setParameter('user', $user);
        } else {
            $qb->andWhere('e.visitorHash = :hash')->setParameter('hash', $visitorHash);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Statistiques d'un projet (cahier §3) : uniquement des métriques
     * réellement mesurées, jamais inventées.
     *
     * @return array{totalViews:int, uniqueViews:int, directViews:int, qrViews:int, shares:int, qrDownloads:int, youtubeOpens:int, proofClicks: array<string,int>}
     */
    public function projectStats(Project $project): array
    {
        $totalViews = (int) $this->countByTypeAndProject($project, AnalyticsEventType::PROJECT_VIEW);
        $uniqueViews = (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT COALESCE(IDENTITY(e.user), e.visitorHash))')
            ->andWhere('e.project = :project')->setParameter('project', $project)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->getQuery()->getSingleScalarResult();

        $directViews = (int) $this->countByTypeAndProject($project, AnalyticsEventType::PROJECT_VIEW, 'direct');
        $qrViews = (int) $this->countByTypeAndProject($project, AnalyticsEventType::PROJECT_VIEW, 'qr');
        $shares = (int) $this->countByTypeAndProject($project, AnalyticsEventType::PROJECT_SHARE);
        $qrDownloads = (int) $this->countByTypeAndProject($project, AnalyticsEventType::QR_DOWNLOAD);
        $youtubeOpens = (int) $this->countByTypeAndProject($project, AnalyticsEventType::YOUTUBE_OPEN);

        $proofClickRows = $this->createQueryBuilder('e')
            ->select('e.metadata AS proofType, COUNT(e.id) AS total')
            ->andWhere('e.project = :project')->setParameter('project', $project)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROOF_CLICK)
            ->groupBy('e.metadata')
            ->getQuery()->getResult();
        $proofClicks = [];
        foreach ($proofClickRows as $row) {
            $proofClicks[(string) $row['proofType']] = (int) $row['total'];
        }

        return [
            'totalViews' => $totalViews,
            'uniqueViews' => $uniqueViews,
            'directViews' => $directViews,
            'qrViews' => $qrViews,
            'shares' => $shares,
            'qrDownloads' => $qrDownloads,
            'youtubeOpens' => $youtubeOpens,
            'proofClicks' => $proofClicks,
        ];
    }

    private function countByTypeAndProject(Project $project, AnalyticsEventType $type, ?string $metadata = null): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.project = :project')->setParameter('project', $project)
            ->andWhere('e.type = :type')->setParameter('type', $type);
        if (null !== $metadata) {
            $qb->andWhere('e.metadata = :metadata')->setParameter('metadata', $metadata);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Agrégat toutes métriques confondues pour un ensemble de projets
     * (cahier §13 : "Mes statistiques" du talent).
     *
     * @param int[] $projectIds
     *
     * @return array{totalViews:int, uniqueViews:int, shares:int, proofClicks:int, qrDownloads:int, qrViews:int}
     */
    public function aggregateStatsForProjects(array $projectIds): array
    {
        if (!$projectIds) {
            return ['totalViews' => 0, 'uniqueViews' => 0, 'shares' => 0, 'proofClicks' => 0, 'qrDownloads' => 0, 'qrViews' => 0];
        }

        $countByType = function (AnalyticsEventType $type, ?string $metadata = null) use ($projectIds) {
            $qb = $this->createQueryBuilder('e')
                ->select('COUNT(e.id)')
                ->andWhere('e.project IN (:ids)')->setParameter('ids', $projectIds)
                ->andWhere('e.type = :type')->setParameter('type', $type);
            if (null !== $metadata) {
                $qb->andWhere('e.metadata = :metadata')->setParameter('metadata', $metadata);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        };

        $uniqueViews = (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT CONCAT(IDENTITY(e.project), \'-\', COALESCE(IDENTITY(e.user), e.visitorHash)))')
            ->andWhere('e.project IN (:ids)')->setParameter('ids', $projectIds)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->getQuery()->getSingleScalarResult();

        return [
            'totalViews' => $countByType(AnalyticsEventType::PROJECT_VIEW),
            'uniqueViews' => $uniqueViews,
            'shares' => $countByType(AnalyticsEventType::PROJECT_SHARE),
            'proofClicks' => $countByType(AnalyticsEventType::PROOF_CLICK),
            'qrDownloads' => $countByType(AnalyticsEventType::QR_DOWNLOAD),
            'qrViews' => $countByType(AnalyticsEventType::PROJECT_VIEW, 'qr'),
        ];
    }

    /**
     * Répartition des clics de preuve par type, pour un ensemble de projets.
     *
     * @param int[] $projectIds
     *
     * @return array<string,int>
     */
    public function proofClicksByTypeForProjects(array $projectIds): array
    {
        if (!$projectIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('e')
            ->select('e.metadata AS proofType, COUNT(e.id) AS total')
            ->andWhere('e.project IN (:ids)')->setParameter('ids', $projectIds)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROOF_CLICK)
            ->groupBy('e.metadata')
            ->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['proofType']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Classement des projets d'un ensemble par nombre de vues (cahier §14).
     *
     * @param int[] $projectIds
     *
     * @return array<int,int> project id => nombre de vues, trié décroissant
     */
    public function viewCountsByProject(array $projectIds, int $limit = 10): array
    {
        if (!$projectIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.project) AS projectId, COUNT(e.id) AS total')
            ->andWhere('e.project IN (:ids)')->setParameter('ids', $projectIds)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->groupBy('e.project')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['projectId']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Évolution des vues jour par jour sur une période (cahier §15) : une
     * seule requête agrégée, jamais une boucle de requêtes par jour.
     *
     * @param int[] $projectIds
     *
     * @return array<string,int> date (Y-m-d) => nombre de vues, toutes les
     *                            dates de la période étant présentes (0 si
     *                            aucune vue) pour un tracé de graphique simple
     */
    public function dailyViewsForProjects(array $projectIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $days = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $days[$cursor->format('Y-m-d')] = 0;
            $cursor = $cursor->modify('+1 day');
        }

        if (!$projectIds) {
            return $days;
        }

        $rows = $this->createQueryBuilder('e')
            ->select("SUBSTRING(e.createdAt, 1, 10) AS day, COUNT(e.id) AS total")
            ->andWhere('e.project IN (:ids)')->setParameter('ids', $projectIds)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->andWhere('e.createdAt >= :from')->setParameter('from', $from)
            ->andWhere('e.createdAt <= :to')->setParameter('to', $to)
            ->groupBy('day')
            ->getQuery()->getResult();

        foreach ($rows as $row) {
            $days[$row['day']] = (int) $row['total'];
        }

        return $days;
    }

    /**
     * Statistiques globales pour l'administration (cahier §21).
     *
     * @return array{totalViews:int, uniqueViews:int, shares:int, qrDownloads:int}
     */
    public function globalStats(): array
    {
        $countByType = function (AnalyticsEventType $type) {
            return (int) $this->createQueryBuilder('e')
                ->select('COUNT(e.id)')
                ->andWhere('e.type = :type')->setParameter('type', $type)
                ->getQuery()->getSingleScalarResult();
        };

        $uniqueViews = (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT CONCAT(IDENTITY(e.project), \'-\', COALESCE(IDENTITY(e.user), e.visitorHash)))')
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->getQuery()->getSingleScalarResult();

        return [
            'totalViews' => $countByType(AnalyticsEventType::PROJECT_VIEW),
            'uniqueViews' => $uniqueViews,
            'shares' => $countByType(AnalyticsEventType::PROJECT_SHARE),
            'qrDownloads' => $countByType(AnalyticsEventType::QR_DOWNLOAD),
        ];
    }

    /**
     * Projets les plus vus/partagés toutes plateformes confondues (cahier
     * §26), indépendamment de leur statut de vérification.
     *
     * @return array<int,int> project id => total, trié décroissant
     */
    public function topProjectsGlobally(AnalyticsEventType $type, int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.project) AS projectId, COUNT(e.id) AS total')
            ->andWhere('e.type = :type')->setParameter('type', $type)
            ->groupBy('e.project')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['projectId']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Nombre de projets distincts consultés par un utilisateur authentifié
     * (cahier §18 : "Projets consultés" côté recruteur).
     */
    public function distinctProjectsViewedByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.project)')
            ->andWhere('e.user = :user')->setParameter('user', $user)
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Technologies les plus recherchées (cahier §19), à partir des
     * événements de recherche strictement anonymes — retourne les
     * identifiants de technologie, à résoudre en noms côté appelant.
     *
     * @return array<int,int> id de technologie => nombre de recherches
     */
    public function topSearchedTechnologyIds(int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.metadata AS technologyId, COUNT(e.id) AS total')
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::TECHNOLOGY_SEARCH)
            ->groupBy('e.metadata')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['technologyId']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Domaines les plus consultés (cahier §25), à partir des vues déjà
     * mesurées — ne recrée pas de classification, ne fait que joindre celle
     * qui existe déjà sur {@see Project}.
     *
     * @return array<string,int> nom du domaine => nombre de vues
     */
    public function topDomainsByViews(int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('d.name AS domainName, COUNT(e.id) AS total')
            ->join('e.project', 'p')
            ->join('p.domain', 'd')
            ->andWhere('e.type = :type')->setParameter('type', AnalyticsEventType::PROJECT_VIEW)
            ->groupBy('d.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['domainName']] = (int) $row['total'];
        }

        return $result;
    }
}
