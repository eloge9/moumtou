<?php

namespace App\Controller;

use App\Entity\Defense;
use App\Entity\Institution;
use App\Entity\Project;
use App\Enum\DefenseStatus;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(EntityManagerInterface $em, ProjectRepository $projectRepository): Response
    {
        $projectsCount = $em->getRepository(Project::class)->count(['status' => ProjectRepository::PUBLIC_STATUSES]);
        $verifiedProjectsCount = $em->getRepository(Project::class)->count(['status' => \App\Enum\ProjectStatus::VERIFIE]);
        $institutionsCount = $em->getRepository(Institution::class)->count([]);

        $recentVerifiedProjects = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->andWhere('p.status = :verifie')->setParameter('verifie', \App\Enum\ProjectStatus::VERIFIE)
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults(3)
            ->getQuery()->getResult();

        $upcomingDefenses = $em->getRepository(Defense::class)->createQueryBuilder('d')
            ->andWhere('d.status = :annoncee')->setParameter('annoncee', DefenseStatus::ANNONCEE)
            ->andWhere('d.date >= :today')->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('d.date', 'ASC')
            ->setMaxResults(3)
            ->getQuery()->getResult();

        return $this->render('home/index.html.twig', [
            'stats' => [
                'projectsCount' => $projectsCount,
                'verifiedProjectsCount' => $verifiedProjectsCount,
                'institutionsCount' => $institutionsCount,
            ],
            'recentVerifiedProjects' => array_map(fn (Project $p) => [
                'slug' => $p->getSlug(),
                'name' => $p->getName(),
                'typeLabel' => $p->getType()->shortLabel(),
                'verified' => true,
                'authorName' => $p->getOwner()->getFullName(),
                'contextLabel' => $p->getInstitution() ? $p->getInstitution()->getName() : $p->getType()->label(),
                'technologies' => array_map(fn ($t) => $t->getName(), $p->getTechnologies()->toArray()),
                'ratingAverage' => $p->getRatingAverage(),
                'ratingsCount' => $p->getRatingsCount(),
                'proofLabels' => array_map(fn ($proof) => $proof->getType()->shortLabel(), $p->getProofs()->toArray()),
            ], $recentVerifiedProjects),
            'upcomingDefenses' => array_map(fn (Defense $d) => [
                'projectName' => $d->getProject()->getName(),
                'specialtyName' => $d->getProject()->getSpecialty()?->getName() ?? $d->getProject()->getType()->label(),
                'institutionName' => $d->getProject()->getInstitution()?->getName() ?? '',
                'date' => $d->getDate(),
                'time' => $d->getTime(),
                'place' => $d->getPlace(),
                'juryCount' => $d->getJuryMembers()->count(),
            ], $upcomingDefenses),
        ]);
    }
}
