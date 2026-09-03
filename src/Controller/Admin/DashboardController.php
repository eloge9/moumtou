<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\Defense;
use App\Entity\Institution;
use App\Entity\Project;
use App\Entity\Report;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\ProjectStatus;
use App\Enum\ReportStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $userRepo = $em->getRepository(User::class);
        $projectRepo = $em->getRepository(Project::class);

        $defenseRepo = $em->getRepository(Defense::class);

        $stats = [
            'usersCount' => $userRepo->count([]),
            'talentsCount' => $this->countByRole($userRepo, 'ROLE_TALENT'),
            'teachersCount' => $this->countByRole($userRepo, 'ROLE_TEACHER'),
            'recruitersCount' => $this->countByRole($userRepo, 'ROLE_RECRUITER'),
            'projectsCount' => $projectRepo->count([]),
            'verifiedProjectsCount' => $projectRepo->count(['status' => ProjectStatus::VERIFIE]),
            'defensesCount' => $defenseRepo->count([]),
            'defensesAnnouncedCount' => $defenseRepo->count(['status' => DefenseStatus::ANNONCEE]),
            'defensesRealizedCount' => $defenseRepo->count(['status' => [DefenseStatus::REALISEE, DefenseStatus::VERIFIEE]]),
            'defensesVerifiedCount' => $defenseRepo->count(['status' => DefenseStatus::VERIFIEE]),
            'institutionsCount' => $em->getRepository(Institution::class)->count([]),
            'technologiesCount' => $em->getRepository(Technology::class)->count([]),
            'commentsCount' => $em->getRepository(Comment::class)->count([]),
            'openReportsCount' => $em->getRepository(Report::class)->count(['status' => ReportStatus::OUVERT]),
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
        ]);
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
