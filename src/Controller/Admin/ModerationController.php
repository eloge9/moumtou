<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\ModerationAction;
use App\Entity\Project;
use App\Entity\Rating;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Enum\CommentStatus;
use App\Enum\ModerationActionType;
use App\Enum\NotificationType;
use App\Enum\ProjectStatus;
use App\Enum\RatingStatus;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Enum\VerificationStatus;
use App\Service\AdminAuditLogger;
use App\Service\NotificationService;
use App\Service\SanctionApplier;
use App\Service\VerificationService;
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

        $suspectRatings = $em->getRepository(Rating::class)->createQueryBuilder('r')
            ->andWhere('r.status IN (:statuses)')->setParameter('statuses', [RatingStatus::SUSPECT, RatingStatus::FLAGGED])
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(30)
            ->getQuery()->getResult();

        return $this->render('admin/moderation.html.twig', [
            'reports' => $reports,
            'reportTargets' => $reportTargets,
            'openCount' => $openCount,
            'inProgressCount' => $inProgressCount,
            'treatedCount' => $treatedCount,
            'pendingProjects' => $pendingProjects,
            'suspectRatings' => $suspectRatings,
        ]);
    }

    /**
     * Examen d'une évaluation suspecte (cahier des charges §10) : l'admin
     * peut la disculper (retour à NORMAL) ou la signaler comme abusive
     * (FLAGGED) ; aucune suppression automatique n'est effectuée ici.
     */
    #[Route('/admin/moderation/evaluations/{id}/examiner', name: 'admin_moderation_rating_review', methods: ['POST'])]
    public function reviewRating(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $rating = $em->getRepository(Rating::class)->find($id);
        if (!$rating) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('moderation-evaluation-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $decision = RatingStatus::tryFrom((string) $request->request->get('decision'));
        if ($decision) {
            $rating->setStatus($decision);
            $em->flush();
            $this->addFlash('succes', 'Évaluation mise à jour : '.$decision->label().'.');
        }

        return $this->redirectToRoute('admin_moderation');
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

        $suspectRatings = $em->getRepository(Rating::class)->createQueryBuilder('r')
            ->andWhere('r.status IN (:statuses)')->setParameter('statuses', [RatingStatus::SUSPECT, RatingStatus::FLAGGED])
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(30)
            ->getQuery()->getResult();

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
            'suspectRatings' => $suspectRatings,
        ]);
    }

    #[Route('/admin/moderation/signalements/{id}/decider', name: 'admin_moderation_report_decide', methods: ['POST'])]
    public function decideReport(int $id, Request $request, EntityManagerInterface $em, SanctionApplier $sanctionApplier, NotificationService $notificationService, AdminAuditLogger $auditLogger, VerificationService $verificationService): Response
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

        // Une décision est toujours consignée, même « aucune action », pour
        // garder un historique complet de chaque signalement traité
        // (cahier des charges §19). Sans choix explicite, on considère que
        // le contenu est conservé tel quel.
        $effectiveAction = $contentAction ?? ModerationActionType::AUCUNE_ACTION;
        if ($target['entity']) {
            $this->applyContentAction($effectiveAction, $target['entity'], $em, $notificationService, $auditLogger, $admin, $reason, $verificationService);
        }

        $moderationAction = new ModerationAction();
        $moderationAction->setReport($report);
        $moderationAction->setAdmin($admin);
        $moderationAction->setTargetType($report->getTargetType());
        $moderationAction->setTargetId($report->getTargetId());
        $moderationAction->setActionType($effectiveAction);
        $moderationAction->setReason($reason);
        $em->persist($moderationAction);

        if ($authorAction && $target['author']) {
            $sanctionApplier->apply($authorAction, $target['author'], $admin, $reason);
        }

        // Rejeté : ni action sur le contenu, ni sanction de l'auteur — le
        // signalement était non fondé (cahier des charges §18).
        $isDismissed = ModerationActionType::AUCUNE_ACTION === $effectiveAction && !$authorAction;
        $report->setStatus($isDismissed ? ReportStatus::REJETE : ReportStatus::TRAITE);
        $em->flush();

        $auditLogger->log(
            $admin,
            $isDismissed ? AdminAuditAction::REPORT_REJECTED : AdminAuditAction::REPORT_RESOLVED,
            'Report',
            $report->getId(),
            $target['title'],
            $reason,
        );

        $this->addFlash('succes', $isDismissed ? 'Signalement rejeté et consigné dans l\'historique.' : 'Décision enregistrée et consignée dans l\'historique de modération.');

        return $this->redirectToRoute('admin_moderation');
    }

    /**
     * Publie en une seule fois tous les projets actuellement en attente de
     * modération, sans passer par le formulaire de décision individuel.
     */
    #[Route('/admin/moderation/projets/publier-tout', name: 'admin_moderation_projects_publish_all', methods: ['POST'])]
    public function publishAllPendingProjects(Request $request, EntityManagerInterface $em, NotificationService $notificationService, AdminAuditLogger $auditLogger, VerificationService $verificationService): Response
    {
        if (!$this->isCsrfTokenValid('moderation-publier-tout', $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $pendingProjects = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->andWhere('p.status = :status')->setParameter('status', ProjectStatus::EN_ATTENTE)
            ->getQuery()->getResult();

        /** @var User $admin */
        $admin = $this->getUser();
        $reason = 'Publication groupée depuis le tableau de bord de modération.';

        foreach ($pendingProjects as $project) {
            $this->applyContentAction(ModerationActionType::PUBLIER, $project, $em, $notificationService, $auditLogger, $admin, $reason, $verificationService);

            $moderationAction = new ModerationAction();
            $moderationAction->setAdmin($admin);
            $moderationAction->setTargetType(ReportTargetType::PROJECT);
            $moderationAction->setTargetId($project->getId());
            $moderationAction->setActionType(ModerationActionType::PUBLIER);
            $moderationAction->setReason($reason);
            $em->persist($moderationAction);
        }
        $em->flush();

        $this->addFlash('succes', \sprintf('%d projet(s) publié(s) en une seule fois.', \count($pendingProjects)));

        return $this->redirectToRoute('admin_moderation');
    }

    #[Route('/admin/moderation/projets/{id}/decider', name: 'admin_moderation_project_decide', methods: ['POST'])]
    public function decideProject(int $id, Request $request, EntityManagerInterface $em, NotificationService $notificationService, AdminAuditLogger $auditLogger, VerificationService $verificationService): Response
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

        /** @var User $admin */
        $admin = $this->getUser();
        $effectiveReason = $reason ?: sprintf('Décision directe depuis le tableau de bord : %s.', $action->label());

        $this->applyContentAction($action, $project, $em, $notificationService, $auditLogger, $admin, $effectiveReason, $verificationService);

        $moderationAction = new ModerationAction();
        $moderationAction->setAdmin($admin);
        $moderationAction->setTargetType(ReportTargetType::PROJECT);
        $moderationAction->setTargetId($project->getId());
        $moderationAction->setActionType($action);
        $moderationAction->setReason($effectiveReason);
        $em->persist($moderationAction);
        $em->flush();

        $this->addFlash('succes', 'Le projet a été mis à jour.');

        return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
    }

    private function applyContentAction(
        ModerationActionType $action,
        ?object $target,
        EntityManagerInterface $em,
        NotificationService $notificationService,
        AdminAuditLogger $auditLogger,
        User $admin,
        string $reason,
        ?VerificationService $verificationService = null,
    ): void {
        if ($target instanceof Comment) {
            match ($action) {
                ModerationActionType::MASQUER => $target->setStatus(CommentStatus::MASQUE),
                ModerationActionType::SUPPRIMER => $target->setStatus(CommentStatus::SUPPRIME),
                ModerationActionType::RESTAURER => $target->setStatus(CommentStatus::VISIBLE),
                default => null,
            };
            $em->flush();

            $commentAuditAction = match ($action) {
                ModerationActionType::MASQUER => AdminAuditAction::COMMENT_HIDDEN,
                ModerationActionType::SUPPRIMER => AdminAuditAction::COMMENT_DELETED,
                ModerationActionType::RESTAURER => AdminAuditAction::COMMENT_RESTORED,
                default => null,
            };
            if ($commentAuditAction) {
                $auditLogger->log($admin, $commentAuditAction, 'Comment', $target->getId(), null, $reason);
            }

            return;
        }

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

        // Cahier des charges — FONCTIONNALITÉ 14 §18 : date/auteur de la
        // vérification, utilisés par le badge public (jamais l'admin lui-même).
        if (ModerationActionType::MARQUER_VERIFIE === $action) {
            $target->setVerifiedAt(new \DateTimeImmutable());
            $target->setVerifiedBy($admin);
        } elseif (ModerationActionType::RETIRER_VERIFICATION === $action) {
            $target->setVerifiedAt(null);
            $target->setVerifiedBy(null);
        }

        if ($target->getStatus() === ProjectStatus::PUBLIE || $target->getStatus() === ProjectStatus::VERIFIE) {
            $target->setPublishedAt($target->getPublishedAt() ?? new \DateTimeImmutable());
        }

        $em->flush();

        $projectAuditAction = match ($action) {
            ModerationActionType::PUBLIER => AdminAuditAction::PROJECT_PUBLISHED,
            ModerationActionType::MARQUER_VERIFIE => AdminAuditAction::PROJECT_VERIFIED,
            ModerationActionType::RETIRER_VERIFICATION => AdminAuditAction::PROJECT_UNVERIFIED,
            ModerationActionType::DEPUBLIER => AdminAuditAction::PROJECT_UNPUBLISHED,
            ModerationActionType::DEMANDER_CORRECTION => AdminAuditAction::CORRECTION_REQUESTED,
            ModerationActionType::MASQUER => AdminAuditAction::PROJECT_HIDDEN,
            ModerationActionType::SUPPRIMER => AdminAuditAction::PROJECT_DELETED,
            default => null,
        };
        if ($projectAuditAction) {
            $auditLogger->log($admin, $projectAuditAction, 'Project', $target->getId(), $target->getName(), $reason);
        }

        // Garde en cohérence une éventuelle demande de vérification déjà
        // ouverte pour ce projet, sans dupliquer notification/audit (cahier
        // des charges — FONCTIONNALITÉ 14 §15).
        if ($verificationService) {
            if (ModerationActionType::MARQUER_VERIFIE === $action) {
                $verificationService->syncFromQuickModeration($target, VerificationStatus::VERIFIEE, $admin, $reason);
            } elseif (ModerationActionType::RETIRER_VERIFICATION === $action) {
                $verificationService->syncFromQuickModeration($target, VerificationStatus::RETIREE, $admin, $reason);
            }
        }

        $projectUrl = $this->generateUrl('app_project_show', ['slug' => $target->getSlug()]);
        if (ModerationActionType::MARQUER_VERIFIE === $action) {
            $notificationService->notify(
                $target->getOwner(),
                NotificationType::PROJECT_VERIFIED,
                'Projet vérifié',
                \sprintf('Votre projet "%s" a été vérifié par l\'administration.', $target->getName()),
                $projectUrl,
            );
        } elseif (ModerationActionType::DEMANDER_CORRECTION === $action) {
            $notificationService->notify(
                $target->getOwner(),
                NotificationType::PROJECT_CORRECTION_REQUESTED,
                'Correction demandée sur votre projet',
                \sprintf('Des corrections sont nécessaires sur votre projet "%s".', $target->getName()),
                $projectUrl,
            );
        }
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
                $comment = $em->getRepository(Comment::class)->find($report->getTargetId());

                return ['entity' => $comment, 'author' => $comment?->getAuthor(), 'title' => 'Commentaire'];
            })(),
        };
    }
}
