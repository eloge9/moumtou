<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\Rating;
use App\Entity\Report;
use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Repository\ProjectRepository;
use App\Service\QrCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProjectController extends AbstractController
{
    #[Route('/projets/{slug}', name: 'app_project_show')]
    public function show(
        string $slug,
        EntityManagerInterface $em,
        RequestStack $requestStack,
        UrlGeneratorInterface $urlGenerator,
        QrCodeGenerator $qrCodeGenerator,
    ): Response {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);

        $this->trackView($project, $em, $requestStack);

        $comments = $em->getRepository(Comment::class)->createQueryBuilder('c')
            ->andWhere('c.project = :project')->setParameter('project', $project)
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.status = :visible')->setParameter('visible', \App\Enum\CommentStatus::VISIBLE)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()->getResult();

        $myRating = null;
        if ($this->getUser()) {
            $myRating = $em->getRepository(Rating::class)->findOneBy(['project' => $project, 'user' => $this->getUser()]);
        }

        $publicUrl = $urlGenerator->generate('app_project_show', ['slug' => $project->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('project/show.html.twig', [
            'project' => $project,
            'comments' => $comments,
            'myRating' => $myRating?->getValue(),
            'publicUrl' => $publicUrl,
            'qrCodeDataUri' => $qrCodeGenerator->generateSvgDataUri($publicUrl),
            'reportReasons' => ReportReason::cases(),
            'youtubeEmbedUrl' => $this->extractYoutubeEmbedUrl($project),
        ]);
    }

    private function extractYoutubeEmbedUrl(Project $project): ?string
    {
        foreach ($project->getProofs() as $proof) {
            if ($proof->getType() !== \App\Enum\ProofType::YOUTUBE) {
                continue;
            }
            if (preg_match('#(?:youtu\.be/|v=|/embed/)([\w-]{6,})#', $proof->getUrl(), $matches)) {
                return 'https://www.youtube.com/embed/'.$matches[1];
            }
        }

        return null;
    }

    #[Route('/projets/{slug}/noter', name: 'app_project_rate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function rate(string $slug, Request $request, EntityManagerInterface $em): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);
        $this->assertCsrf($request, 'note');

        $value = $request->request->getInt('value');
        if ($value < 1 || $value > 5) {
            $this->addFlash('erreur', 'La note doit être comprise entre 1 et 5.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $rating = $em->getRepository(Rating::class)->findOneBy(['project' => $project, 'user' => $user]);
        if (!$rating) {
            $rating = new Rating();
            $rating->setProject($project);
            $rating->setUser($user);
            $em->persist($rating);
        }
        $rating->setValue($value);
        $em->flush();

        $this->recomputeRatingAggregate($project, $em);

        $this->addFlash('succes', 'Votre évaluation a été enregistrée.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/commenter', name: 'app_project_comment', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function comment(string $slug, Request $request, EntityManagerInterface $em): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);
        $this->assertCsrf($request, 'commentaire');

        $content = trim((string) $request->request->get('content'));
        if ($content === '') {
            $this->addFlash('erreur', 'Le commentaire ne peut pas être vide.');

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        $comment = new Comment();
        $comment->setProject($project);
        $comment->setAuthor($this->getUser());
        $comment->setContent($content);
        $em->persist($comment);
        $em->flush();

        $this->addFlash('succes', 'Votre commentaire a été publié.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/signaler', name: 'app_project_report', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function report(string $slug, Request $request, EntityManagerInterface $em): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $this->assertViewable($project);
        $this->assertCsrf($request, 'signalement');

        $reason = ReportReason::tryFrom((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Sélectionnez un motif de signalement.');

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

        $this->addFlash('succes', 'Votre signalement a été transmis à l\'équipe de modération.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Csrf\Exception\InvalidCsrfTokenException();
        }
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
