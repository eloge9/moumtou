<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Enum\ReportTargetType;
use App\Enum\VerificationStatus;
use App\Repository\VerificationRequestRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\VerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace talent de la fonctionnalité vérification (cahier des charges —
 * FONCTIONNALITÉ 14 §6/§23/§31). Le statut réel n'est jamais accepté depuis
 * le client : uniquement dérivé de {@see \App\Entity\VerificationRequest} en
 * base (§27).
 */
#[IsGranted('ROLE_TALENT')]
class VerificationController extends AbstractController
{
    #[Route('/projets/{slug}/verification/demander', name: 'app_project_verification_request', methods: ['POST'])]
    public function requestProject(string $slug, Request $request, EntityManagerInterface $em, VerificationService $verificationService): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        if (!$this->isCsrfTokenValid('verification-projet-'.$project->getId(), $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $missing = $verificationService->eligibilityForProject($project);
        if ($missing) {
            $this->addFlash('erreur', 'Impossible de demander la vérification : '.implode(' ', $missing));

            return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
        }

        /** @var User $requester */
        $requester = $this->getUser();
        $verificationService->requestProjectVerification($project, $requester);

        $this->addFlash('succes', 'Votre demande de vérification a été envoyée à l\'administration.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/verification/resoumettre', name: 'app_project_verification_resubmit', methods: ['POST'])]
    public function resubmitProject(string $slug, Request $request, EntityManagerInterface $em, VerificationRequestRepository $verificationRequestRepository, VerificationService $verificationService): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        if (!$this->isCsrfTokenValid('verification-projet-'.$project->getId(), $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $verificationRequest = $verificationRequestRepository->findLatestForTarget(ReportTargetType::PROJECT, $project->getId());
        if (!$verificationRequest || VerificationStatus::CORRECTION_DEMANDEE !== $verificationRequest->getStatus()) {
            throw $this->createNotFoundException();
        }

        $verificationService->resubmit($verificationRequest);

        $this->addFlash('succes', 'Votre projet a été resoumis pour vérification.');

        return $this->redirectToRoute('app_project_show', ['slug' => $slug]);
    }

    #[Route('/mon-profil/verification/demander', name: 'app_profile_verification_request', methods: ['POST'])]
    public function requestProfile(Request $request, VerificationService $verificationService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('verification-profil-'.$user->getId(), $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $missing = $verificationService->eligibilityForProfile($user);
        if ($missing) {
            $this->addFlash('erreur', 'Impossible de demander la vérification de votre profil : '.implode(' ', $missing));

            return $this->redirectToRoute('app_profile_edit');
        }

        $verificationService->requestProfileVerification($user);

        $this->addFlash('succes', 'Votre demande de vérification de profil a été envoyée à l\'administration.');

        return $this->redirectToRoute('app_profile_edit');
    }

    #[Route('/mon-profil/verification/resoumettre', name: 'app_profile_verification_resubmit', methods: ['POST'])]
    public function resubmitProfile(Request $request, VerificationRequestRepository $verificationRequestRepository, VerificationService $verificationService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('verification-profil-'.$user->getId(), $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $verificationRequest = $verificationRequestRepository->findLatestForTarget(ReportTargetType::PROFILE, $user->getId());
        if (!$verificationRequest || VerificationStatus::CORRECTION_DEMANDEE !== $verificationRequest->getStatus()) {
            throw $this->createNotFoundException();
        }

        $verificationService->resubmit($verificationRequest);

        $this->addFlash('succes', 'Votre profil a été resoumis pour vérification.');

        return $this->redirectToRoute('app_profile_edit');
    }
}
