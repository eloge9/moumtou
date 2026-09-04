<?php

namespace App\Controller;

use App\Entity\ContactRequest;
use App\Entity\Experience;
use App\Entity\RecruiterFavorite;
use App\Entity\TalentView;
use App\Entity\Technology;
use App\Entity\User;
use App\Entity\UserInstitution;
use App\Enum\ContactRequestStatus;
use App\Enum\DefenseStatus;
use App\Enum\InstitutionContext;
use App\Enum\ProjectStatus;
use App\Form\ExperienceType;
use App\Form\ProfileEditType;
use App\Repository\AnalyticsEventRepository;
use App\Repository\ContactRequestRepository;
use App\Repository\ProjectPhotoRepository;
use App\Repository\ProjectRepository;
use App\Repository\RecruiterFavoriteRepository;
use App\Repository\VerificationRequestRepository;
use App\Enum\ReportTargetType;
use App\Service\AvatarUploader;
use App\Service\QrCodeGenerator;
use App\Service\StatsPeriod;
use App\Service\VerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProfileController extends AbstractController
{
    #[Route('/profils/{slug}', name: 'app_profile_show')]
    public function show(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        QrCodeGenerator $qrCodeGenerator,
        UrlGeneratorInterface $urlGenerator,
        RecruiterFavoriteRepository $favoriteRepository,
        ContactRequestRepository $contactRequestRepository,
        ProjectPhotoRepository $projectPhotoRepository,
        AnalyticsEventRepository $analyticsEventRepository,
    ): Response {
        $user = $em->getRepository(User::class)->findOneBy(['slug' => $slug]);
        if (!$user) {
            throw $this->createNotFoundException('Profil introuvable.');
        }

        $isOwner = $this->getUser() === $user;

        // Un visiteur ne voit que les projets publics ; le propriétaire voit tout.
        $projects = $user->getProjects()->filter(function ($project) use ($isOwner) {
            return $isOwner || \in_array($project->getStatus(), ProjectRepository::PUBLIC_STATUSES, true);
        });

        $verifiedCount = 0;
        $verifiedDefenseCount = 0;
        $totalViews = 0;
        foreach ($projects as $project) {
            if ($project->getStatus() === ProjectStatus::VERIFIE) {
                ++$verifiedCount;
            }
            if ($project->getDefense() && DefenseStatus::VERIFIEE === $project->getDefense()->getStatus()) {
                ++$verifiedDefenseCount;
            }
            $totalViews += $project->getViewsCount();
        }

        $ratings = array_filter(array_map(fn ($p) => $p->getRatingsCount() ? $p->getRatingAverage() : null, $projects->toArray()));
        $averageRating = $ratings ? array_sum($ratings) / \count($ratings) : 0;

        $defenseProjects = $projects->filter(fn ($p) => $p->getDefense() !== null);

        $publicUrl = $urlGenerator->generate('app_profile_show', ['slug' => $user->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);

        // « Mes statistiques » (cahier des charges — FONCTIONNALITÉ 12
        // §13-§16) : uniquement calculé pour le propriétaire du profil,
        // jamais pour un visiteur — évite des requêtes inutiles et respecte
        // la confidentialité (§32 : "le talent voit uniquement les
        // statistiques de ses propres projets").
        $talentStats = null;
        if ($isOwner) {
            $ownedProjectIds = array_map(fn ($p) => $p->getId(), $projects->toArray());
            [$periodFrom, $periodTo, $period] = StatsPeriod::resolve($request->query->get('periode'));

            $talentStats = [
                'aggregate' => $analyticsEventRepository->aggregateStatsForProjects($ownedProjectIds),
                'proofClicks' => $analyticsEventRepository->proofClicksByTypeForProjects($ownedProjectIds),
                'topProjects' => $this->resolveTopProjects($projects->toArray(), $analyticsEventRepository->viewCountsByProject($ownedProjectIds, 5)),
                'dailyViews' => $analyticsEventRepository->dailyViewsForProjects($ownedProjectIds, $periodFrom, $periodTo),
                'period' => $period,
            ];

            // Cahier des charges — FONCTIONNALITÉ 12 §17 : "ajouts aux
            // favoris, demandes de contact" — uniquement pertinent pour un
            // compte talent (destinataire de ces actions recruteur), à
            // partir des entités déjà existantes (FONCTIONNALITÉ 7),
            // jamais dupliquées ici.
            if (\in_array('ROLE_TALENT', $user->getRoles(), true)) {
                $talentStats['favoritesReceivedCount'] = (int) $em->getRepository(RecruiterFavorite::class)->count(['talent' => $user]);
                $talentStats['contactRequestsReceivedCount'] = (int) $em->getRepository(ContactRequest::class)->count(['talent' => $user]);
            }
        }

        // Journal des consultations recruteur (cahier — FONCTIONNALITÉ 7
        // §20), et état favori/demande pour afficher les bons boutons —
        // jamais pour un visiteur, ni pour le propriétaire consultant son
        // propre profil.
        $recruiterContext = null;
        $viewer = $this->getUser();
        $isTalent = \in_array('ROLE_TALENT', $user->getRoles(), true);
        if (!$isOwner && $isTalent && $viewer instanceof User && \in_array('ROLE_RECRUITER', $viewer->getRoles(), true)) {
            $view = new TalentView();
            $view->setRecruiter($viewer);
            $view->setTalent($user);
            $em->persist($view);
            $em->flush();

            $recruiterContext = [
                'isFavorite' => $favoriteRepository->isFavorite($viewer, $user),
                'hasPendingRequest' => $contactRequestRepository->hasPendingRequest($viewer, $user),
            ];
        }

        return $this->render('profile/show.html.twig', [
            'profileUser' => $user,
            'isOwner' => $isOwner,
            'projects' => $projects,
            'coverPhotos' => $projectPhotoRepository->findCoversForProjects($projects->toArray()),
            'defenseProjects' => $defenseProjects,
            'stats' => [
                'publishedCount' => \count($projects),
                'verifiedCount' => $verifiedCount,
                'verifiedDefenseCount' => $verifiedDefenseCount,
                'averageRating' => $averageRating,
                'totalViews' => $totalViews,
            ],
            'publicUrl' => $publicUrl,
            'qrCodeDataUri' => $qrCodeGenerator->generateSvgDataUri($publicUrl),
            'recruiterContext' => $recruiterContext,
            'talentStats' => $talentStats,
            'statsPeriodChoices' => StatsPeriod::CHOICES,
        ]);
    }

    #[Route('/mon-profil/modifier', name: 'app_profile_edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, EntityManagerInterface $em, AvatarUploader $avatarUploader, VerificationRequestRepository $verificationRequestRepository, VerificationService $verificationService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ProfileEditType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $photo = $form->get('photo')->getData();
            if ($photo) {
                $avatarUploader->upload($user, $photo);
            }

            foreach ($this->parseTechnologies($form->get('technologiesInput')->getData(), $em) as $technology) {
                $user->addTechnology($technology);
            }

            if ($user->getInstitution()) {
                $this->syncInstitutionAttachment($user, $em);
            }

            $user->setProfileCompleted(true);

            $em->flush();

            $this->addFlash('succes', 'Votre profil a été mis à jour.');

            return $this->redirectToRoute('app_profile_show', ['slug' => $user->getSlug()]);
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
            'user' => $user,
            'verificationRequest' => $verificationRequestRepository->findLatestForTarget(ReportTargetType::PROFILE, $user->getId()),
            'verificationEligibility' => $verificationService->eligibilityForProfile($user),
        ]);
    }

    #[Route('/mon-profil/photo/supprimer', name: 'app_profile_remove_photo', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function removePhoto(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-photo-profil', $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getPhoto()) {
            $path = $this->getParameter('kernel.project_dir').'/public/'.$user->getPhoto();
            if (is_file($path)) {
                @unlink($path);
            }
            $user->setPhoto(null);
            $em->flush();
        }

        $this->addFlash('succes', 'Votre photo de profil a été supprimée.');

        return $this->redirectToRoute('app_profile_edit');
    }

    #[Route('/mon-profil/experiences/ajouter', name: 'app_profile_add_experience')]
    #[IsGranted('ROLE_USER')]
    public function addExperience(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $experience = new Experience();
        $form = $this->createForm(ExperienceType::class, $experience);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->addExperience($experience);
            $em->persist($experience);
            $em->flush();

            $this->addFlash('succes', 'Expérience ajoutée.');

            return $this->redirectToRoute('app_profile_show', ['slug' => $user->getSlug()]);
        }

        return $this->render('profile/add_experience.html.twig', ['form' => $form]);
    }

    #[Route('/mon-profil/experiences/{id}/supprimer', name: 'app_profile_remove_experience', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function removeExperience(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $experience = $em->getRepository(Experience::class)->find($id);

        if (!$experience || $experience->getUser() !== $user) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('supprimer-experience-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $em->remove($experience);
        $em->flush();

        $this->addFlash('succes', 'Expérience supprimée.');

        return $this->redirectToRoute('app_profile_show', ['slug' => $user->getSlug()]);
    }

    /**
     * Alimente la table de rattachement multi-établissement (gestion des
     * établissements §5/§7) en plus du champ `institution` "principal", sans
     * jamais créer de doublon pour un même (utilisateur, établissement, contexte).
     */
    private function syncInstitutionAttachment(User $user, EntityManagerInterface $em): void
    {
        $context = \in_array('ROLE_TEACHER', $user->getRoles(), true) ? InstitutionContext::ENSEIGNANT : InstitutionContext::ETUDIANT;

        $existing = $em->getRepository(UserInstitution::class)->findOneBy([
            'user' => $user,
            'institution' => $user->getInstitution(),
            'context' => $context,
        ]);

        if ($existing) {
            $existing->setActive(true);

            return;
        }

        $attachment = new UserInstitution();
        $attachment->setInstitution($user->getInstitution());
        $attachment->setContext($context);
        $user->addInstitutionAttachment($attachment);
        $em->persist($attachment);
    }

    /**
     * @return Technology[]
     */
    private function parseTechnologies(?string $raw, EntityManagerInterface $em): array
    {
        if (!$raw) {
            return [];
        }

        $names = array_unique(array_filter(array_map('trim', explode(',', $raw))));
        $repository = $em->getRepository(Technology::class);
        $technologies = [];

        foreach ($names as $name) {
            $technology = $repository->findOneBy(['name' => $name]);
            if (!$technology) {
                $technology = new Technology();
                $technology->setName($name);
                $em->persist($technology);
            }
            $technologies[] = $technology;
        }

        return $technologies;
    }

    /**
     * @param \App\Entity\Project[] $ownedProjects
     * @param array<int,int>        $viewCountsById
     *
     * @return array<int, array{project: \App\Entity\Project, views: int}>
     */
    private function resolveTopProjects(array $ownedProjects, array $viewCountsById): array
    {
        $byId = [];
        foreach ($ownedProjects as $project) {
            $byId[$project->getId()] = $project;
        }

        $top = [];
        foreach ($viewCountsById as $projectId => $views) {
            if (isset($byId[$projectId])) {
                $top[] = ['project' => $byId[$projectId], 'views' => $views];
            }
        }

        return $top;
    }
}
