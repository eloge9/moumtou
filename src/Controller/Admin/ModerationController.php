<?php

namespace App\Controller\Admin;

use App\Entity\ModerationAction;
use App\Entity\Project;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\ModerationActionType;
use App\Enum\ProjectStatus;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Service\SanctionApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ModerationController extends AbstractController
{
    #[Route('/admin/moderation', name: 'admin_moderation')]
    public function index(EntityManagerInterface $em): Response
    {
        $reports = $em->getRepository(Report::class)->createQueryBuilder('r')
            ->andWhere('r.status = :ouvert')->setParameter('ouvert', ReportStatus::OUVERT)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()->getResult();

        $openCount = \count($reports);
        $inProgressCount = $em->getRepository(Report::class)->count(['status' => ReportStatus::EN_COURS]);
        $treatedCount = $em->getRepository(Report::class)->count(['status' => ReportStatus::TRAITE]);

        $pendingProjects = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->andWhere('p.status = :status')->setParameter('status', ProjectStatus::EN_ATTENTE)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()->getResult();

        // Résout la cible de chaque signalement pour l'affichage (titre, auteur…).
        $reportTargets = [];
        foreach ($reports as $report) {
            $reportTargets[$report->getId()] = $this->resolveTarget($report, $em);
        }

        return $this->render('admin/moderation.html.twig', [
            'reports' => $reports,
            'reportTargets' => $reportTargets,
            'openCount' => $openCount,
            'inProgressCount' => $inProgressCount,
            'treatedCount' => $treatedCount,
            'pendingProjects' => $pendingProjects,
        ]);
    }

    #[Route('/admin/moderation/signalements/{id}', name: 'admin_moderation_report_show')]
    public function showReport(int $id, EntityManagerInterface $em): Response
    {
        $report = $em->getRepository(Report::class)->find($id);
        if (!$report) {
            throw $this->createNotFoundException();
        }

        $target = $this->resolveTarget($report, $em);
        $siblingReports = $em->getRepository(Report::class)->findBy([
            'targetType' => $report->getTargetType(),
            'targetId' => $report->getTargetId(),
        ]);

        $reports = $em->getRepository(Report::class)->createQueryBuilder('r')
            ->andWhere('r.status = :ouvert')->setParameter('ouvert', ReportStatus::OUVERT)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()->getResult();

        $reportTargets = [];
        foreach ($reports as $r) {
            $reportTargets[$r->getId()] = $this->resolveTarget($r, $em);
        }

        return $this->render('admin/moderation.html.twig', [
            'reports' => $reports,
            'reportTargets' => $reportTargets,
            'openCount' => \count($reports),
            'inProgressCount' => $em->getRepository(Report::class)->count(['status' => ReportStatus::EN_COURS]),
            'treatedCount' => $em->getRepository(Report::class)->count(['status' => ReportStatus::TRAITE]),
            'pendingProjects' => [],
            'report' => $report,
            'target' => $target,
            'siblingReports' => $siblingReports,
        ]);
    }

    #[Route('/admin/moderation/signalements/{id}/decider', name: 'admin_moderation_report_decide', methods: ['POST'])]
    public function decideReport(int $id, Request $request, EntityManagerInterface $em, SanctionApplier $sanctionApplier): Response
    {
        $report = $em->getRepository(Report::class)->find($id);
        if (!$report) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('moderation-signalement-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $contentAction = ModerationActionType::tryFrom((string) $request->request->get('content_action'));
        $authorAction = $request->request->get('author_action');
        $reason = trim((string) $request->request->get('reason'));

        if (!$reason) {
            $this->addFlash('erreur', 'Le motif est obligatoire.');

            return $this->redirectToRoute('admin_moderation_report_show', ['id' => $id]);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $target = $this->resolveTarget($report, $em);

        if ($contentAction && $target['entity']) {
            $this->applyContentAction($contentAction, $target['entity'], $em);

            $moderationAction = new ModerationAction();
            $moderationAction->setReport($report);
            $moderationAction->setAdmin($admin);
            $moderationAction->setTargetType($report->getTargetType());
            $moderationAction->setTargetId($report->getTargetId());
            $moderationAction->setActionType($contentAction);
            $moderationAction->setReason($reason);
            $em->persist($moderationAction);
        }

        if ($authorAction && $target['author']) {
            $sanctionApplier->apply($authorAction, $target['author'], $admin, $reason);
        }

        $report->setStatus(ReportStatus::TRAITE);
        $em->flush();

        $this->addFlash('succes', 'Décision enregistrée et consignée dans l\'historique de modération.');

        return $this->redirectToRoute('admin_moderation');
    }

    #[Route('/admin/moderation/projets/{id}/decider', name: 'admin_moderation_project_decide', methods: ['POST'])]
    public function decideProject(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('moderation-projet-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $action = ModerationActionType::tryFrom((string) $request->request->get('action'));
        if (!$action) {
            throw $this->createNotFoundException();
        }

        $reason = trim((string) $request->request->get('reason'));
        if (ModerationActionType::DEMANDER_CORRECTION === $action && !$reason) {
            $this->addFlash('erreur', 'Précisez le motif et l\'action attendue pour que le talent puisse corriger son projet.');

            return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
        }

        $this->applyContentAction($action, $project, $em);

        /** @var User $admin */
        $admin = $this->getUser();
        $moderationAction = new ModerationAction();
        $moderationAction->setAdmin($admin);
        $moderationAction->setTargetType(ReportTargetType::PROJECT);
        $moderationAction->setTargetId($project->getId());
        $moderationAction->setActionType($action);
        $moderationAction->setReason($reason ?: sprintf('Décision directe depuis le tableau de bord : %s.', $action->label()));
        $em->persist($moderationAction);
        $em->flush();

        $this->addFlash('succes', 'Le projet a été mis à jour.');

        return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
    }

    private function applyContentAction(ModerationActionType $action, object $target, EntityManagerInterface $em): void
    {
        if (!$target instanceof Project) {
            return;
        }

        match ($action) {
            ModerationActionType::PUBLIER => $target->setStatus(ProjectStatus::PUBLIE),
            ModerationActionType::MARQUER_VERIFIE => $target->setStatus(ProjectStatus::VERIFIE),
            // Retirer la vérification ramène le projet à "publié" : il reste
            // visible publiquement (cahier §6/§31 : publication ≠ vérification),
            // seul le badge "vérifié" disparaît.
            ModerationActionType::RETIRER_VERIFICATION => $target->setStatus(ProjectStatus::PUBLIE),
            ModerationActionType::DEPUBLIER => $target->setStatus(ProjectStatus::EN_ATTENTE),
            ModerationActionType::DEMANDER_CORRECTION => $target->setStatus(ProjectStatus::VERIFICATION_DEMANDEE),
            ModerationActionType::MASQUER, ModerationActionType::SUPPRIMER => $target->setStatus(ProjectStatus::REJETE),
            default => null,
        };

        if ($target->getStatus() === ProjectStatus::PUBLIE || $target->getStatus() === ProjectStatus::VERIFIE) {
            $target->setPublishedAt($target->getPublishedAt() ?? new \DateTimeImmutable());
        }

        $em->flush();
    }

    /**
     * @return array{entity: object|null, author: User|null, title: string}
     */
    private function resolveTarget(Report $report, EntityManagerInterface $em): array
    {
        return match ($report->getTargetType()) {
            ReportTargetType::PROJECT => (function () use ($report, $em) {
                $project = $em->getRepository(Project::class)->find($report->getTargetId());

                return ['entity' => $project, 'author' => $project?->getOwner(), 'title' => $project?->getName() ?? 'Projet supprimé'];
            })(),
            ReportTargetType::PROFILE => (function () use ($report, $em) {
                $user = $em->getRepository(User::class)->find($report->getTargetId());

                return ['entity' => $user, 'author' => $user, 'title' => $user?->getFullName() ?? 'Profil supprimé'];
            })(),
            ReportTargetType::COMMENT => (function () use ($report, $em) {
                $comment = $em->getRepository(\App\Entity\Comment::class)->find($report->getTargetId());

                return ['entity' => $comment, 'author' => $comment?->getAuthor(), 'title' => 'Commentaire'];
            })(),
        };
    }
}
