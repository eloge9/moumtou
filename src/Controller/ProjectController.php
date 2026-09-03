<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\Rating;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\NotificationType;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Repository\ProjectRepository;
use App\Repository\VerificationRequestRepository;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\ProjectVoter;
use App\Service\AnalyticsTracker;
use App\Service\NotificationService;
use App\Service\QrCodeGenerator;
use App\Service\RatingIntegrityChecker;
use App\Service\VerificationService;
use App\Service\YoutubeUrlExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProjectController extends AbstractController
{
    private const MAX_COMMENT_LENGTH = 2000;

    #[Route('/projets/{slug}', name: 'app_project_show')]
    public function show(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        RequestStack $requestStack,
        UrlGeneratorInterface $urlGenerator,
        QrCodeGenerator $qrCodeGenerator,
        YoutubeUrlExtractor $youtubeUrlExtractor,
        AnalyticsTracker $analyticsTracker,
        VerificationRequestRepository $verificationRequestRepository,
        VerificationService $verificationService,
    ): Response {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);

        // Une vue n'est comptabilisée que si le projet est réellement
        // accessible publiquement (cahier des charges — FONCTIONNALITÉ 12
        // §4) : un propriétaire ou un admin prévisualisant un brouillon ne
        // génère jamais de vue, même si assertViewable() le laisse passer.
        if (\in_array($project->getStatus(), ProjectRepository::PUBLIC_STATUSES, true)) {
            $this->trackView($project, $em, $requestStack);

            /** @var User|null $viewer */
            $viewer = $this->getUser();
            $source = 'qr' === $request->query->get('src') ? 'qr' : 'direct';
            $analyticsTracker->trackProjectView($project, $viewer, $source);
        }

        // Cahier des charges — FONCTIONNALITÉ 17 §4/§11 : la page projet
        // affiche l'auteur de chaque commentaire et de chaque réponse ; sans
        // ces jointures, Twig déclenchait un chargement paresseux par
        // commentaire (auteur + collection des réponses), soit 2 requêtes
        // supplémentaires par commentaire (mesuré : 27 requêtes pour 5
        // commentaires avant correction, 17 après — voir rapport final).
        $comments = $em->getRepository(Comment::class)->createQueryBuilder('c')
            ->leftJoin('c.author', 'author')->addSelect('author')
            // Association OneToOne côté inverse (User::$recruiterProfile) :
            // toujours chargée immédiatement par Doctrine, une requête par
            // auteur distinct si elle n'est pas explicitement jointe ici.
            ->leftJoin('author.recruiterProfile', 'authorRecruiterProfile')->addSelect('authorRecruiterProfile')
            ->leftJoin('c.replies', 'replies')->addSelect('replies')
            ->leftJoin('replies.author', 'replyAuthor')->addSelect('replyAuthor')
            ->leftJoin('replyAuthor.recruiterProfile', 'replyAuthorRecruiterProfile')->addSelect('replyAuthorRecruiterProfile')
            ->andWhere('c.project = :project')->setParameter('project', $project)
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.status = :visible')->setParameter('visible', CommentStatus::VISIBLE)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('replies.createdAt', 'ASC')
            ->getQuery()->getResult();

        $myRating = null;
        if ($this->getUser()) {
            $myRating = $em->getRepository(Rating::class)->findOneBy(['project' => $project, 'user' => $this->getUser()]);
        }

        $publicUrl = $urlGenerator->generate('app_project_show', ['slug' => $project->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        // Le QR code encode l'URL publique avec un marqueur d'origine
        // (cahier des charges — FONCTIONNALITÉ 12 §7/§8) : la destination
        // reste rigoureusement la même page, seule une visite provenant du
        // QR peut être distinguée d'une visite directe. Le lien copié/partagé
        // (publicUrl) et les balises Open Graph restent l'URL propre, non
        // modifiée (cahier — FONCTIONNALITÉ 11 §4 : stabilité de l'URL).
        $qrCodeUrl = $publicUrl.'?src=qr';

        $verificationRequest = null;
        $verificationEligibility = [];
        if ($this->isGranted(ProjectVoter::EDIT, $project)) {
            $verificationRequest = $verificationRequestRepository->findLatestForTarget(ReportTargetType::PROJECT, $project->getId());
            $verificationEligibility = $verificationService->eligibilityForProject($project);
        }

        return $this->render('project/show.html.twig', [
            'project' => $project,
            'comments' => $comments,
            'myRating' => $myRating?->getValue(),
            'publicUrl' => $publicUrl,
            'qrCodeDataUri' => $qrCodeGenerator->generateSvgDataUri($qrCodeUrl),
            'qrCodePngDataUri' => $qrCodeGenerator->generatePngDataUri($qrCodeUrl),
            'reportReasons' => ReportReason::cases(),
            'youtubeVideoId' => $youtubeUrlExtractor->extractVideoId($project),
            'verificationRequest' => $verificationRequest,
            'verificationEligibility' => $verificationEligibility,
        ]);
    }


    #[Route('/projets/{slug}/noter', name: 'app_project_rate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function rate(string $slug, Request $request, EntityManagerInterface $em, RatingIntegrityChecker $integrityChecker, NotificationService $notificationService): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);
        $this->assertCsrf($request, 'note');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if ($project->getOwner() === $user) {
            $this->addFlash('erreur', 'Vous ne pouvez pas évaluer votre propre projet.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $value = (int) $request->request->get('value');
        if ($value < 1 || $value > 5) {
            $this->addFlash('erreur', 'La note doit être comprise entre 1 et 5.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $rating = $em->getRepository(Rating::class)->findOneBy(['project' => $project, 'user' => $user]);
        $isNewRating = !$rating;
        if (!$rating) {
            $rating = new Rating();
            $rating->setProject($project);
            $rating->setUser($user);
            $rating->setIpAddress($request->getClientIp());
            $em->persist($rating);
        } else {
            $rating->setUpdatedAt(new \DateTimeImmutable());
        }
        $rating->setValue($value);
        $rating->setStatus($integrityChecker->evaluate($user, $em));
        $em->flush();

        $this->recomputeRatingAggregate($project, $em);

        // Notifié seulement à la création (§26 : pas de doublon à chaque
        // modification d'une note déjà existante).
        if ($isNewRating) {
            $notificationService->notify(
                $project->getOwner(),
                NotificationType::PROJECT_RATING_RECEIVED,
                'Nouvelle évaluation',
                \sprintf('Votre projet "%s" a reçu une nouvelle évaluation.', $project->getName()),
                $this->generateUrl('app_project_show', ['slug' => $slug]),
            );
        }

        $this->addFlash('succes', 'Votre évaluation a été enregistrée.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/annuler-note', name: 'app_project_unrate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unrate(string $slug, Request $request, EntityManagerInterface $em): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);
        $this->assertCsrf($request, 'annuler-note');

        $rating = $em->getRepository(Rating::class)->findOneBy(['project' => $project, 'user' => $this->getUser()]);
        if ($rating) {
            $em->remove($rating);
            $em->flush();
            $this->recomputeRatingAggregate($project, $em);
            $this->addFlash('succes', 'Votre évaluation a été supprimée.');
        }

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/commenter', name: 'app_project_comment', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function comment(string $slug, Request $request, EntityManagerInterface $em, RateLimiterFactory $commentLimiter, NotificationService $notificationService): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);
        $this->assertCsrf($request, 'commentaire');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$commentLimiter->create('user-'.$user->getId())->consume(1)->isAccepted()) {
            $this->addFlash('erreur', 'Vous avez publié trop de commentaires récemment. Merci de patienter.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $content = $this->validatedContent((string) $request->request->get('content'));
        if (null === $content) {
            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $comment = new Comment();
        $comment->setProject($project);
        $comment->setAuthor($this->getUser());
        $comment->setContent($content);
        $em->persist($comment);
        $em->flush();

        // Jamais de notification à soi-même (§19).
        if ($project->getOwner() !== $user) {
            $notificationService->notify(
                $project->getOwner(),
                NotificationType::COMMENT_RECEIVED,
                'Nouveau commentaire',
                \sprintf('%s a commenté votre projet "%s".', $user->getFullName(), $project->getName()),
                $this->generateUrl('app_project_show', ['slug' => $slug]),
            );
        }

        $this->addFlash('succes', 'Votre commentaire a été publié.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/commentaires/{id}/repondre', name: 'app_comment_reply', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function replyToComment(int $id, Request $request, EntityManagerInterface $em, RateLimiterFactory $commentLimiter, NotificationService $notificationService): Response
    {
        $parent = $em->getRepository(Comment::class)->find($id);
        if (!$parent) {
            throw $this->createNotFoundException();
        }

        $this->assertViewable($parent->getProject());
        $this->assertCsrf($request, 'repondre-commentaire-'.$id);

        // Une réponse est un commentaire comme un autre du point de vue du
        // risque de spam : même compteur que app_project_comment (cahier
        // des charges — FONCTIONNALITÉ 15 §13).
        if (!$commentLimiter->create('user-'.$this->getUser()->getId())->consume(1)->isAccepted()) {
            $this->addFlash('erreur', 'Vous avez publié trop de commentaires récemment. Merci de patienter.');

            return $this->redirectToRoute('app_project_show', ['slug' => $parent->getProject()->getSlug()]);
        }

        $content = $this->validatedContent((string) $request->request->get('content'));
        if (null === $content) {
            return $this->redirectToRoute('app_project_show', ['slug' => $parent->getProject()->getSlug()]);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $reply = new Comment();
        $reply->setProject($parent->getProject());
        $reply->setAuthor($user);
        $reply->setContent($content);
        $reply->setParent($parent);
        $em->persist($reply);
        $em->flush();

        // Jamais de notification lorsqu'on répond à son propre commentaire (§19).
        if ($parent->getAuthor() !== $user) {
            $notificationService->notify(
                $parent->getAuthor(),
                NotificationType::COMMENT_REPLY,
                'Réponse à votre commentaire',
                \sprintf('%s a répondu à votre commentaire sur "%s".', $user->getFullName(), $parent->getProject()->getName()),
                $this->generateUrl('app_project_show', ['slug' => $parent->getProject()->getSlug()]),
            );
        }

        $this->addFlash('succes', 'Votre réponse a été publiée.');

        return $this->redirectToRoute('app_project_show', ['slug' => $parent->getProject()->getSlug()]);
    }

    #[Route('/commentaires/{id}/modifier', name: 'app_comment_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function editComment(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $comment = $em->getRepository(Comment::class)->find($id);
        if (!$comment) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment);
        $this->assertCsrf($request, 'modifier-commentaire-'.$id);

        $slug = $comment->getProject()->getSlug();
        $content = $this->validatedContent((string) $request->request->get('content'));
        if (null === $content) {
            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $comment->setContent($content);
        $comment->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('succes', 'Votre commentaire a été modifié.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/commentaires/{id}/supprimer', name: 'app_comment_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteComment(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $comment = $em->getRepository(Comment::class)->find($id);
        if (!$comment) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment);
        $this->assertCsrf($request, 'supprimer-commentaire-'.$id);

        $slug = $comment->getProject()->getSlug();
        // Suppression logique (cahier des charges §14) : préserve les
        // réponses des autres utilisateurs plutôt que de les supprimer en
        // cascade, et permet une restauration par l'administrateur.
        $comment->setStatus(CommentStatus::SUPPRIME);
        $em->flush();

        $this->addFlash('succes', 'Le commentaire a été supprimé.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/commentaires/{id}/signaler', name: 'app_comment_report', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reportComment(int $id, Request $request, EntityManagerInterface $em, RateLimiterFactory $reportLimiter, NotificationService $notificationService): Response
    {
        $comment = $em->getRepository(Comment::class)->find($id);
        if (!$comment) {
            throw $this->createNotFoundException();
        }

        $this->assertViewable($comment->getProject());
        $this->assertCsrf($request, 'signalement-commentaire-'.$id);
        $slug = $comment->getProject()->getSlug();

        if (!$reportLimiter->create('user-'.$this->getUser()->getId())->consume(1)->isAccepted()) {
            $this->addFlash('erreur', 'Vous avez transmis trop de signalements récemment. Merci de patienter.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $reason = ReportReason::tryFrom((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Sélectionnez un motif de signalement.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        if ($this->hasOpenReport($em, ReportTargetType::COMMENT, $id)) {
            $this->addFlash('erreur', 'Vous avez déjà signalé ce commentaire, il est en cours d\'examen.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $report = new Report();
        $report->setReporter($this->getUser());
        $report->setTargetType(ReportTargetType::COMMENT);
        $report->setTargetId($id);
        $report->setReason($reason);
        $report->setDetails(trim((string) $request->request->get('details')) ?: null);
        $em->persist($report);
        $em->flush();

        $this->notifyAdminsOfNewReport($em, $notificationService, $report, sprintf('Un commentaire du projet « %s » a été signalé.', $comment->getProject()->getName()));

        $this->addFlash('succes', 'Votre signalement a été transmis à l\'équipe de modération.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/signaler', name: 'app_project_report', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function report(string $slug, Request $request, EntityManagerInterface $em, RateLimiterFactory $reportLimiter, NotificationService $notificationService): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);
        $this->assertCsrf($request, 'signalement');

        if (!$reportLimiter->create('user-'.$this->getUser()->getId())->consume(1)->isAccepted()) {
            $this->addFlash('erreur', 'Vous avez transmis trop de signalements récemment. Merci de patienter.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $reason = ReportReason::tryFrom((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Sélectionnez un motif de signalement.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        if ($this->hasOpenReport($em, ReportTargetType::PROJECT, $project->getId())) {
            $this->addFlash('erreur', 'Vous avez déjà signalé ce projet, il est en cours d\'examen.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $report = new Report();
        $report->setReporter($this->getUser());
        $report->setTargetType(ReportTargetType::PROJECT);
        $report->setTargetId($project->getId());
        $report->setReason($reason);
        $report->setDetails(trim((string) $request->request->get('details')) ?: null);
        $em->persist($report);
        $em->flush();

        $this->notifyAdminsOfNewReport($em, $notificationService, $report, sprintf('Le projet « %s » a été signalé.', $project->getName()));

        $this->addFlash('succes', 'Votre signalement a été transmis à l\'équipe de modération.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    /**
     * Notifie l'ensemble des administrateurs qu'un nouveau signalement
     * attend un traitement (cahier des charges — FONCTIONNALITÉ 9 §44) :
     * réutilise le NotificationService de la FONCTIONNALITÉ 8, sans créer
     * de second système de notification.
     */
    private function notifyAdminsOfNewReport(EntityManagerInterface $em, NotificationService $notificationService, Report $report, string $message): void
    {
        $admins = $em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->getQuery()
            ->getResult();

        foreach ($admins as $admin) {
            $notificationService->notify(
                $admin,
                NotificationType::REPORT_RECEIVED,
                NotificationType::REPORT_RECEIVED->label(),
                $message,
                $this->generateUrl('admin_moderation_report_show', ['id' => $report->getId()]),
            );
        }
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }
    }

    /**
     * Valide et normalise le contenu d'un commentaire/réponse (cahier des
     * charges §25/§35 : ni vide, ni excessivement long). Ajoute un message
     * flash et renvoie null si invalide, sinon le contenu épuré.
     */
    private function validatedContent(string $raw): ?string
    {
        $content = trim($raw);
        if ('' === $content) {
            $this->addFlash('erreur', 'Le commentaire ne peut pas être vide.');

            return null;
        }
        if (mb_strlen($content) > self::MAX_COMMENT_LENGTH) {
            $this->addFlash('erreur', \sprintf('Le commentaire est trop long (%d caractères maximum).', self::MAX_COMMENT_LENGTH));

            return null;
        }

        return $content;
    }

    /**
     * Empêche un même utilisateur de signaler plusieurs fois le même
     * contenu tant qu'un premier signalement est encore ouvert (cahier des
     * charges §17/§35 : pas de doublon).
     */
    private function hasOpenReport(EntityManagerInterface $em, ReportTargetType $targetType, int $targetId): bool
    {
        return null !== $em->getRepository(Report::class)->findOneBy([
            'reporter' => $this->getUser(),
            'targetType' => $targetType,
            'targetId' => $targetId,
            'status' => [ReportStatus::OUVERT, ReportStatus::EN_COURS],
        ]);
    }

    private function assertViewable(?Project $project): void
    {
        if (!$project) {
            throw new NotFoundHttpException('Projet introuvable.');
        }

        $isPublic = \in_array($project->getStatus(), ProjectRepository::PUBLIC_STATUSES, true);
        $isOwnerOrAdmin = $this->getUser() && ($project->getOwner() === $this->getUser() || $this->isGranted('ROLE_ADMIN'));

        if (!$isPublic && !$isOwnerOrAdmin) {
            throw new NotFoundHttpException('Ce projet n\'est pas encore publié.');
        }
    }

    private function trackView(Project $project, EntityManagerInterface $em, RequestStack $requestStack): void
    {
        $session = $requestStack->getSession();
        $seen = $session->get('projets_vus', []);
        if (\in_array($project->getId(), $seen, true)) {
            return;
        }

        $project->incrementViewsCount();
        $seen[] = $project->getId();
        $session->set('projets_vus', $seen);
        $em->flush();
    }

    private function recomputeRatingAggregate(Project $project, EntityManagerInterface $em): void
    {
        $result = $em->getRepository(Rating::class)->createQueryBuilder('r')
            ->select('AVG(r.value) AS moyenne, COUNT(r.id) AS total')
            ->andWhere('r.project = :project')->setParameter('project', $project)
            ->getQuery()->getSingleResult();

        $project->setRatingAverage(round((float) $result['moyenne'], 1));
        $project->setRatingsCount((int) $result['total']);
        $em->flush();
    }
}
