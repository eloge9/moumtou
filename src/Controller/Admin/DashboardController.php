<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\Defense;
use App\Entity\Institution;
use App\Entity\Project;
use App\Entity\Rating;
use App\Entity\Report;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\AnalyticsEventType;
use App\Enum\DefenseStatus;
use App\Enum\ProjectStatus;
use App\Enum\ReportStatus;
use App\Repository\AnalyticsEventRepository;
use App\Repository\ErrorLogRepository;
use App\Repository\UserRepository;
use App\Repository\VerificationRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(EntityManagerInterface $em, AnalyticsEventRepository $analyticsEventRepository, VerificationRequestRepository $verificationRequestRepository, ErrorLogRepository $errorLogRepository): Response
    {
        $userRepo = $em->getRepository(User::class);
        $projectRepo = $em->getRepository(Project::class);

        $defenseRepo = $em->getRepository(Defense::class);
        $institutionRepo = $em->getRepository(Institution::class);

        $globalAnalytics = $analyticsEventRepository->globalStats();

        $stats = [
            'usersCount' => $userRepo->count([]),
            'talentsCount' => $this->countByRole($userRepo, 'ROLE_TALENT'),
            'teachersCount' => $this->countByRole($userRepo, 'ROLE_TEACHER'),
            'recruitersCount' => $this->countByRole($userRepo, 'ROLE_RECRUITER'),
            'projectsCount' => $projectRepo->count([]),
            'verifiedProjectsCount' => $projectRepo->count(['status' => ProjectStatus::VERIFIE]),
            'pendingProjectsCount' => $projectRepo->count(['status' => ProjectStatus::EN_ATTENTE]),
            // "Masqué" et "refusé" partagent le même statut REJETE dans le
            // modèle existant (cahier — FONCTIONNALITÉ 9) : pas de double
            // comptage, un seul chiffre les couvre tous les deux.
            'rejectedProjectsCount' => $projectRepo->count(['status' => ProjectStatus::REJETE]),
            'defensesCount' => $defenseRepo->count([]),
            'defensesAnnouncedCount' => $defenseRepo->count(['status' => DefenseStatus::ANNONCEE]),
            'defensesRealizedCount' => $defenseRepo->count(['status' => [DefenseStatus::REALISEE, DefenseStatus::VERIFIEE]]),
            'defensesVerifiedCount' => $defenseRepo->count(['status' => DefenseStatus::VERIFIEE]),
            'institutionsCount' => $institutionRepo->count([]),
            'verifiedInstitutionsCount' => $institutionRepo->count(['verified' => true]),
            'verifiedProfilesCount' => $userRepo->count(['profileVerified' => true]),
            'pendingVerificationsCount' => $verificationRequestRepository->countOpen(),
            'technologiesCount' => $em->getRepository(Technology::class)->count([]),
            'commentsCount' => $em->getRepository(Comment::class)->count([]),
            'ratingsCount' => $em->getRepository(Rating::class)->count([]),
            'openReportsCount' => $em->getRepository(Report::class)->count(['status' => ReportStatus::OUVERT]),
            'criticalErrorsCount' => $errorLogRepository->summary()['critical24h'],
            'totalViews' => $globalAnalytics['totalViews'],
            'uniqueViews' => $globalAnalytics['uniqueViews'],
            'sharesCount' => $globalAnalytics['shares'],
        ];

        $pendingProjects = $projectRepo->createQueryBuilder('p')
            ->andWhere('p.status = :status')->setParameter('status', ProjectStatus::EN_ATTENTE)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults(10)
            ->getQuery()->getResult();

        $technologyDemand = $projectRepo->createQueryBuilder('p')
            ->select('t.name AS name, COUNT(p.id) AS total')
            ->join('p.technologies', 't')
            ->groupBy('t.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults(6)
            ->getQuery()->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'pendingProjects' => $pendingProjects,
            'technologyDemand' => $technologyDemand,
            'topDomains' => $analyticsEventRepository->topDomainsByViews(5),
            'topSearchedTechnologies' => $this->resolveSearchedTechnologies($em, $analyticsEventRepository->topSearchedTechnologyIds(6)),
            'mostRepresentedDomains' => $this->mostRepresentedDomains($em, 5),
            'mostRepresentedInstitutions' => $this->mostRepresentedInstitutions($em, 5),
            'topProjectsByViews' => $this->resolveProjects($em, $analyticsEventRepository->topProjectsGlobally(AnalyticsEventType::PROJECT_VIEW, 5)),
            'topProjectsByShares' => $this->resolveProjects($em, $analyticsEventRepository->topProjectsGlobally(AnalyticsEventType::PROJECT_SHARE, 5)),
            'topRatedProjects' => $projectRepo->createQueryBuilder('p')
                ->andWhere('p.ratingsCount > 0')
                ->orderBy('p.ratingAverage', 'DESC')->addOrderBy('p.ratingsCount', 'DESC')
                ->setMaxResults(5)->getQuery()->getResult(),
            'mostCommentedProjects' => $this->mostCommentedProjects($em, 5),
        ]);
    }

    /**
     * @param array<int,int> $viewsByProjectId project id => total, trié
     *
     * @return array<int, array{project: Project, total: int}>
     */
    private function resolveProjects(EntityManagerInterface $em, array $viewsByProjectId): array
    {
        if (!$viewsByProjectId) {
            return [];
        }

        $projects = $em->getRepository(Project::class)->findBy(['id' => array_keys($viewsByProjectId)]);
        $byId = [];
        foreach ($projects as $project) {
            $byId[$project->getId()] = $project;
        }

        $result = [];
        foreach ($viewsByProjectId as $projectId => $total) {
            if (isset($byId[$projectId])) {
                $result[] = ['project' => $byId[$projectId], 'total' => $total];
            }
        }

        return $result;
    }

    /**
     * @param array<int,int> $countByTechnologyId technology id => nombre de recherches, trié
     *
     * @return array<string,int> nom de la technologie => nombre de recherches
     */
    private function resolveSearchedTechnologies(EntityManagerInterface $em, array $countByTechnologyId): array
    {
        if (!$countByTechnologyId) {
            return [];
        }

        $technologies = $em->getRepository(Technology::class)->findBy(['id' => array_keys($countByTechnologyId)]);
        $nameById = [];
        foreach ($technologies as $technology) {
            $nameById[$technology->getId()] = $technology->getName();
        }

        $result = [];
        foreach ($countByTechnologyId as $technologyId => $total) {
            if (isset($nameById[$technologyId])) {
                $result[$nameById[$technologyId]] = $total;
            }
        }

        return $result;
    }

    /**
     * @return array<string,int> nom du domaine => nombre de projets
     */
    private function mostRepresentedDomains(EntityManagerInterface $em, int $limit): array
    {
        $rows = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->select('d.name AS name, COUNT(p.id) AS total')
            ->join('p.domain', 'd')
            ->groupBy('d.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['name']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * @return array<string,int> nom de l'établissement => nombre de projets
     */
    private function mostRepresentedInstitutions(EntityManagerInterface $em, int $limit): array
    {
        $rows = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->select('i.name AS name, COUNT(p.id) AS total')
            ->join('p.institution', 'i')
            ->groupBy('i.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['name']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * @return array<int, array{project: Project, total: int}>
     */
    private function mostCommentedProjects(EntityManagerInterface $em, int $limit): array
    {
        $rows = $em->getRepository(Comment::class)->createQueryBuilder('c')
            ->select('IDENTITY(c.project) AS projectId, COUNT(c.id) AS total')
            ->groupBy('c.project')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $countByProjectId = [];
        foreach ($rows as $row) {
            $countByProjectId[(int) $row['projectId']] = (int) $row['total'];
        }

        return $this->resolveProjects($em, $countByProjectId);
    }

    private function countByRole(UserRepository $userRepo, string $role): int
    {
        return (int) $userRepo->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"'.$role.'"%')
            ->getQuery()->getSingleScalarResult();
    }
}
