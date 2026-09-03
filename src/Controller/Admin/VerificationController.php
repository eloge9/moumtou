<?php

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Project;
use App\Entity\ProjectProof;
use App\Entity\User;
use App\Entity\VerificationRequest;
use App\Enum\ReportTargetType;
use App\Enum\VerificationStatus;
use App\Repository\VerificationRequestRepository;
use App\Service\VerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace administrateur "Vérifications" (cahier des charges —
 * FONCTIONNALITÉ 14 §9/§10/§11/§12). Le badge affiché publiquement est
 * toujours dérivé du statut réel en base ({@see VerificationService}) —
 * cette classe ne fait que déclencher les transitions, jamais directement.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/verifications')]
class VerificationController extends AbstractController
{
    #[Route('', name: 'admin_verifications')]
    public function index(Request $request, VerificationRequestRepository $repository, EntityManagerInterface $em): Response
    {
        $targetType = ReportTargetType::tryFrom((string) $request->query->get('type', ''));
        $status = VerificationStatus::tryFrom((string) $request->query->get('status', ''));
        $author = trim((string) $request->query->get('author', ''));
        $dateFrom = trim((string) $request->query->get('date_from', ''));
        $dateTo = trim((string) $request->query->get('date_to', ''));
        $domainId = (int) $request->query->get('domain', 0);
        $institutionId = (int) $request->query->get('institution', 0);
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $repository->search($targetType, $status, $author ?: null, $dateFrom ?: null, $dateTo ?: null, $domainId ?: null, $institutionId ?: null, $page);

        return $this->render('admin/verifications.html.twig', [
            'adminNav' => 'verifications',
            'requests' => $result['items'],
            'targets' => $this->resolveLabels($em, $result['items']),
            'targetType' => $targetType,
            'status' => $status,
            'author' => $author,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'domainId' => $domainId,
            'institutionId' => $institutionId,
            'targetTypes' => ReportTargetType::cases(),
            'statuses' => VerificationStatus::cases(),
            'domains' => $em->getRepository(Domain::class)->findBy([], ['name' => 'ASC']),
            'institutions' => $em->getRepository(Institution::class)->findBy([], ['name' => 'ASC']),
            'page' => $page,
            'pageCount' => (int) ceil($result['total'] / VerificationRequestRepository::PER_PAGE),
            'total' => $result['total'],
        ]);
    }

    #[Route('/{id}', name: 'admin_verification_show', requirements: ['id' => '\d+'])]
    public function show(int $id, EntityManagerInterface $em, VerificationRequestRepository $repository): Response
    {
        $verificationRequest = $em->getRepository(VerificationRequest::class)->find($id);
        if (!$verificationRequest) {
            throw $this->createNotFoundException();
        }

        $project = null;
        $profileUser = null;
        if (ReportTargetType::PROJECT === $verificationRequest->getTargetType()) {
            $project = $em->getRepository(Project::class)->find($verificationRequest->getTargetId());
        } else {
            $profileUser = $em->getRepository(User::class)->find($verificationRequest->getTargetId());
        }

        return $this->render('admin/verification_show.html.twig', [
            'adminNav' => 'verifications',
            'verificationRequest' => $verificationRequest,
            'project' => $project,
            'profileUser' => $profileUser,
            'history' => $verificationRequest->getEvents(),
        ]);
    }

    #[Route('/{id}/prendre-en-charge', name: 'admin_verification_claim', methods: ['POST'])]
    public function claim(int $id, Request $request, EntityManagerInterface $em, VerificationService $verificationService): Response
    {
        $verificationRequest = $this->findOrFail($em, $id);
        $this->checkCsrf($request, $id);

        /** @var User $admin */
        $admin = $this->getUser();
        $verificationService->claim($verificationRequest, $admin);

        $this->addFlash('succes', 'Demande prise en charge.');

        return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
    }

    #[Route('/{id}/valider', name: 'admin_verification_approve', methods: ['POST'])]
    public function approve(int $id, Request $request, EntityManagerInterface $em, VerificationService $verificationService): Response
    {
        $verificationRequest = $this->findOrFail($em, $id);
        $this->checkCsrf($request, $id);

        /** @var User $admin */
        $admin = $this->getUser();
        $comment = trim((string) $request->request->get('comment')) ?: null;
        $verificationService->approve($verificationRequest, $admin, $comment);

        $this->addFlash('succes', 'Vérification validée.');

        return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
    }

    #[Route('/{id}/corriger', name: 'admin_verification_request_correction', methods: ['POST'])]
    public function requestCorrection(int $id, Request $request, EntityManagerInterface $em, VerificationService $verificationService): Response
    {
        $verificationRequest = $this->findOrFail($em, $id);
        $this->checkCsrf($request, $id);

        $reason = trim((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Le motif est obligatoire pour demander une correction.');

            return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $verificationService->requestCorrection($verificationRequest, $admin, $reason);

        $this->addFlash('succes', 'Correction demandée au talent.');

        return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
    }

    #[Route('/{id}/refuser', name: 'admin_verification_reject', methods: ['POST'])]
    public function reject(int $id, Request $request, EntityManagerInterface $em, VerificationService $verificationService): Response
    {
        $verificationRequest = $this->findOrFail($em, $id);
        $this->checkCsrf($request, $id);

        $reason = trim((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Le motif est obligatoire pour refuser une demande.');

            return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $verificationService->reject($verificationRequest, $admin, $reason);

        $this->addFlash('succes', 'Demande refusée.');

        return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
    }

    #[Route('/{id}/retirer', name: 'admin_verification_revoke', methods: ['POST'])]
    public function revoke(int $id, Request $request, EntityManagerInterface $em, VerificationService $verificationService): Response
    {
        $verificationRequest = $this->findOrFail($em, $id);
        $this->checkCsrf($request, $id);

        $reason = trim((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Le motif est obligatoire pour retirer une vérification.');

            return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $verificationService->revoke($verificationRequest, $admin, $reason);

        $this->addFlash('succes', 'Vérification retirée.');

        return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
    }

    /**
     * Coche/décoche une preuve comme examinée par l'administrateur en cours
     * d'examen (cahier des charges — FONCTIONNALITÉ 14 §8) — jamais accordé
     * automatiquement, jamais accessible au talent.
     */
    #[Route('/{id}/preuves/{proofId}/basculer', name: 'admin_verification_proof_toggle', methods: ['POST'])]
    public function toggleProofReviewed(int $id, int $proofId, Request $request, EntityManagerInterface $em): Response
    {
        $verificationRequest = $this->findOrFail($em, $id);
        $this->checkCsrf($request, $id);

        if (ReportTargetType::PROJECT !== $verificationRequest->getTargetType()) {
            throw $this->createNotFoundException();
        }

        $proof = $em->getRepository(ProjectProof::class)->find($proofId);
        if (!$proof || $proof->getProject()?->getId() !== $verificationRequest->getTargetId()) {
            throw $this->createNotFoundException();
        }

        $proof->setReviewed(!$proof->isReviewed());
        $em->flush();

        return $this->redirectToRoute('admin_verification_show', ['id' => $id]);
    }

    private function findOrFail(EntityManagerInterface $em, int $id): VerificationRequest
    {
        $verificationRequest = $em->getRepository(VerificationRequest::class)->find($id);
        if (!$verificationRequest) {
            throw $this->createNotFoundException();
        }

        return $verificationRequest;
    }

    private function checkCsrf(Request $request, int $id): void
    {
        if (!$this->isCsrfTokenValid('verification-admin-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }
    }

    /**
     * @param VerificationRequest[] $requests
     *
     * @return array<int, array{label: string, url: string|null}> libellé/lien
     *                                                             d'affichage par id de demande, résolus en 2 requêtes
     *                                                             (pas de N+1) — cahier §30
     */
    private function resolveLabels(EntityManagerInterface $em, array $requests): array
    {
        $projectIds = [];
        $userIds = [];
        foreach ($requests as $r) {
            if (ReportTargetType::PROJECT === $r->getTargetType()) {
                $projectIds[] = $r->getTargetId();
            } else {
                $userIds[] = $r->getTargetId();
            }
        }

        $projectsById = [];
        if ($projectIds) {
            foreach ($em->getRepository(Project::class)->findBy(['id' => $projectIds]) as $p) {
                $projectsById[$p->getId()] = $p;
            }
        }
        $usersById = [];
        if ($userIds) {
            foreach ($em->getRepository(User::class)->findBy(['id' => $userIds]) as $u) {
                $usersById[$u->getId()] = $u;
            }
        }

        $labels = [];
        foreach ($requests as $r) {
            if (ReportTargetType::PROJECT === $r->getTargetType()) {
                $p = $projectsById[$r->getTargetId()] ?? null;
                $labels[$r->getId()] = ['label' => $p?->getName() ?? 'Projet supprimé', 'entity' => $p];
            } else {
                $u = $usersById[$r->getTargetId()] ?? null;
                $labels[$r->getId()] = ['label' => $u?->getFullName() ?? 'Profil supprimé', 'entity' => $u];
            }
        }

        return $labels;
    }
}
