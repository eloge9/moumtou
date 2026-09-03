<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\ProjectProof;
use App\Entity\User;
use App\Repository\AnalyticsEventRepository;
use App\Repository\ProjectRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\AnalyticsTracker;
use App\Service\QrCodeGenerator;
use App\Service\StatsPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Points d'entrée nécessaires pour mesurer, côté serveur, des interactions
 * qui mènent normalement vers un fichier ou une URL externe (cahier des
 * charges — FONCTIONNALITÉ 12 §7-§11) : téléchargement du QR code, clic sur
 * une preuve, partage, ouverture de la vidéo. Chaque action reste
 * fonctionnellement identique à avant (même destination, même fichier) —
 * seule une mesure est ajoutée en plus.
 *
 * L'accès à ces routes respecte exactement les mêmes règles de visibilité
 * que la page publique du projet (propriétaire/admin peuvent prévisualiser
 * un projet non public) ; la mesure elle-même n'est en revanche jamais
 * enregistrée pour un projet qui n'est pas réellement public (cahier §4/§18) —
 * ni brouillon, ni en attente, ni masqué, ni supprimé.
 */
class ProjectAnalyticsController extends AbstractController
{
    /**
     * Statistiques d'un projet (cahier des charges — FONCTIONNALITÉ 12
     * §3/§14) : réservées au propriétaire ou à un administrateur — jamais
     * accessibles à un visiteur ni à un autre talent (§32).
     */
    #[Route('/projets/{slug}/statistiques', name: 'app_project_stats')]
    public function stats(string $slug, Request $request, EntityManagerInterface $em, AnalyticsEventRepository $analyticsEventRepository): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        [$periodFrom, $periodTo, $period] = StatsPeriod::resolve($request->query->get('periode'));

        $commentsCount = (int) $em->getRepository(Comment::class)->count(['project' => $project]);

        return $this->render('project/stats.html.twig', [
            'project' => $project,
            'projectStats' => $analyticsEventRepository->projectStats($project),
            'dailyViews' => $analyticsEventRepository->dailyViewsForProjects([$project->getId()], $periodFrom, $periodTo),
            'period' => $period,
            'statsPeriodChoices' => StatsPeriod::CHOICES,
            'commentsCount' => $commentsCount,
        ]);
    }

    #[Route('/projets/{slug}/qr.svg', name: 'app_project_qr_svg', methods: ['GET'])]
    public function qrSvg(string $slug, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, QrCodeGenerator $qrCodeGenerator, AnalyticsTracker $analyticsTracker): Response
    {
        return $this->downloadQrCode($slug, 'svg', $em, $urlGenerator, $qrCodeGenerator, $analyticsTracker);
    }

    #[Route('/projets/{slug}/qr.png', name: 'app_project_qr_png', methods: ['GET'])]
    public function qrPng(string $slug, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, QrCodeGenerator $qrCodeGenerator, AnalyticsTracker $analyticsTracker): Response
    {
        return $this->downloadQrCode($slug, 'png', $em, $urlGenerator, $qrCodeGenerator, $analyticsTracker);
    }

    private function downloadQrCode(string $slug, string $format, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, QrCodeGenerator $qrCodeGenerator, AnalyticsTracker $analyticsTracker): Response
    {
        $project = $this->findViewableProject($slug, $em);

        $publicUrl = $urlGenerator->generate('app_project_show', ['slug' => $project->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        $qrCodeUrl = $publicUrl.'?src=qr';

        if ('png' === $format) {
            $dataUri = $qrCodeGenerator->generatePngDataUri($qrCodeUrl);
            $mimeType = 'image/png';
        } else {
            $dataUri = $qrCodeGenerator->generateSvgDataUri($qrCodeUrl);
            $mimeType = 'image/svg+xml';
        }

        $this->trackIfPublic($project, fn () => $analyticsTracker->trackQrDownload($project, $this->currentUser(), $format));

        // Le générateur renvoie une data URI (base64) ; on en extrait le
        // contenu binaire pour le servir comme un vrai téléchargement,
        // fichier généré à la volée jamais stocké (cahier §24/§25).
        $binary = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true) ?: '';

        $response = new Response($binary);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            sprintf('moumtou-%s.%s', $project->getSlug(), $format),
        ));

        return $response;
    }

    #[Route('/projets/{slug}/preuves/{proofId}/ouvrir', name: 'app_project_proof_open', requirements: ['proofId' => '\d+'], methods: ['GET'])]
    public function openProof(string $slug, int $proofId, EntityManagerInterface $em, AnalyticsTracker $analyticsTracker): RedirectResponse
    {
        $project = $this->findViewableProject($slug, $em);

        $proof = $em->getRepository(ProjectProof::class)->find($proofId);
        if (!$proof || $proof->getProject() !== $project) {
            throw $this->createNotFoundException();
        }

        $this->trackIfPublic($project, fn () => $analyticsTracker->trackProofClick($project, $this->currentUser(), $proof->getType()));

        return new RedirectResponse($proof->getUrl());
    }

    #[Route('/projets/{slug}/partage/enregistrer', name: 'app_project_share_track', methods: ['POST'])]
    public function trackShare(string $slug, EntityManagerInterface $em, AnalyticsTracker $analyticsTracker): Response
    {
        $project = $this->findViewableProject($slug, $em);
        $this->trackIfPublic($project, fn () => $analyticsTracker->trackShare($project, $this->currentUser()));

        return new Response('', 204);
    }

    #[Route('/projets/{slug}/video/ouverture', name: 'app_project_video_track', methods: ['POST'])]
    public function trackVideoOpen(string $slug, EntityManagerInterface $em, AnalyticsTracker $analyticsTracker): Response
    {
        $project = $this->findViewableProject($slug, $em);
        $this->trackIfPublic($project, fn () => $analyticsTracker->trackYoutubeOpen($project, $this->currentUser()));

        return new Response('', 204);
    }

    /**
     * Mêmes règles de visibilité que {@see ProjectController::assertViewable()} :
     * public pour tout visiteur, ou propriétaire/admin en prévisualisation.
     */
    private function findViewableProject(string $slug, EntityManagerInterface $em): Project
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable.');
        }

        $isPublic = \in_array($project->getStatus(), ProjectRepository::PUBLIC_STATUSES, true);
        $isOwnerOrAdmin = $this->getUser() && ($project->getOwner() === $this->getUser() || $this->isGranted('ROLE_ADMIN'));
        if (!$isPublic && !$isOwnerOrAdmin) {
            throw $this->createNotFoundException('Ce projet n\'est pas encore publié.');
        }

        return $project;
    }

    /**
     * N'enregistre la mesure que si le projet est réellement public (cahier
     * §4/§18) : un propriétaire/admin en prévisualisation peut toujours
     * utiliser la fonctionnalité (redirection, téléchargement…) sans que
     * cela ne pollue les statistiques.
     */
    private function trackIfPublic(Project $project, callable $track): void
    {
        if (\in_array($project->getStatus(), ProjectRepository::PUBLIC_STATUSES, true)) {
            $track();
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
