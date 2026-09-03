<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ReportTargetType;
use App\Repository\VerificationRequestRepository;
use App\Service\QrCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consultation et décision de modération sur un projet (cahier des charges
 * §19 : "l'Admin doit pouvoir consulter les preuves avant de vérifier").
 * Le traitement de la décision reste sur la route existante
 * `admin_moderation_project_decide` (Admin\ModerationController) — pas de
 * doublon de logique, seulement un point d'entrée dédié pour l'examiner.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/projets')]
class ProjectController extends AbstractController
{
    private const PER_PAGE = 20;

    #[Route('', name: 'admin_projects')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $status = ProjectStatus::tryFrom((string) $request->query->get('status', ''));
        $type = ProjectType::tryFrom((string) $request->query->get('type', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $qb = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->leftJoin('p.owner', 'owner')->addSelect('owner')
            ->orderBy('p.createdAt', 'DESC');

        if ($query) {
            $qb->andWhere('p.name LIKE :q OR owner.firstName LIKE :q OR owner.lastName LIKE :q')->setParameter('q', '%'.$query.'%');
        }
        if ($status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }
        if ($type) {
            $qb->andWhere('p.type = :type')->setParameter('type', $type);
        }

        $total = (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $projects = $qb->setFirstResult(self::PER_PAGE * ($page - 1))->setMaxResults(self::PER_PAGE)->getQuery()->getResult();

        return $this->render('admin/projects.html.twig', [
            'adminNav' => 'projects',
            'projects' => $projects,
            'query' => $query,
            'status' => $status,
            'type' => $type,
            'statuses' => ProjectStatus::cases(),
            'types' => ProjectType::cases(),
            'page' => $page,
            'pageCount' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    #[Route('/{id}', name: 'admin_project_show', requirements: ['id' => '\d+'])]
    public function show(int $id, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, QrCodeGenerator $qrCodeGenerator, VerificationRequestRepository $verificationRequestRepository): Response
    {
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw $this->createNotFoundException();
        }

        // URL publique et QR code (cahier des charges — FONCTIONNALITÉ 11
        // §22) : uniquement si le projet a un slug, c'est-à-dire s'il a déjà
        // été soumis au moins une fois (un projet jamais persisté via le
        // formulaire de publication n'existe pas en base).
        $publicUrl = $project->getSlug()
            ? $urlGenerator->generate('app_project_show', ['slug' => $project->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;

        return $this->render('admin/project_show.html.twig', [
            'adminNav' => 'projects',
            'project' => $project,
            'publicUrl' => $publicUrl,
            'qrCodeDataUri' => $publicUrl ? $qrCodeGenerator->generateSvgDataUri($publicUrl) : null,
            'actionTypes' => \App\Enum\ModerationActionType::cases(),
            'verificationRequest' => $verificationRequestRepository->findLatestForTarget(ReportTargetType::PROJECT, $id),
            'history' => $em->getRepository(\App\Entity\ModerationAction::class)->createQueryBuilder('ma')
                ->andWhere('ma.targetType = :type')->setParameter('type', \App\Enum\ReportTargetType::PROJECT)
                ->andWhere('ma.targetId = :id')->setParameter('id', $id)
                ->orderBy('ma.createdAt', 'DESC')
                ->getQuery()->getResult(),
        ]);
    }
}
