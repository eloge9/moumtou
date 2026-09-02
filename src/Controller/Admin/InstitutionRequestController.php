<?php

namespace App\Controller\Admin;

use App\Entity\Institution;
use App\Entity\InstitutionRequest;
use App\Entity\User;
use App\Enum\InstitutionRequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Traitement des demandes d'ajout d'établissement (gestion des
 * établissements §4) : une demande n'active jamais automatiquement un
 * établissement — seule une décision explicite de l'administrateur le fait.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/etablissements/demandes')]
class InstitutionRequestController extends AbstractController
{
    #[Route('', name: 'admin_institution_requests')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $status = InstitutionRequestStatus::tryFrom((string) $request->query->get('status', '')) ?? InstitutionRequestStatus::EN_ATTENTE;

        return $this->render('admin/institution_requests.html.twig', [
            'adminNav' => 'institution_requests',
            'status' => $status,
            'requests' => $em->getRepository(InstitutionRequest::class)->findBy(['status' => $status], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}/decider', name: 'admin_institution_request_decide', methods: ['POST'])]
    public function decide(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $institutionRequest = $em->getRepository(InstitutionRequest::class)->find($id);
        if (!$institutionRequest) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('institution-request-decider-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $decision = (string) $request->request->get('decision');
        $note = trim((string) $request->request->get('note'));

        /** @var User $admin */
        $admin = $this->getUser();

        match ($decision) {
            'accepter' => $this->accept($institutionRequest, $em),
            'refuser' => $institutionRequest->setStatus(InstitutionRequestStatus::REFUSEE),
            'corrections' => $institutionRequest->setStatus(InstitutionRequestStatus::CORRECTIONS_DEMANDEES),
            default => throw $this->createNotFoundException(),
        };

        $institutionRequest->setAdminNote($note ?: null);
        $institutionRequest->setDecidedBy($admin);
        $institutionRequest->setDecidedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('succes', match ($decision) {
            'accepter' => 'La demande a été acceptée : l\'établissement est désormais dans le catalogue officiel.',
            'refuser' => 'La demande a été refusée.',
            default => 'Des corrections ont été demandées au talent.',
        });

        return $this->redirectToRoute('admin_institution_requests');
    }

    private function accept(InstitutionRequest $institutionRequest, EntityManagerInterface $em): void
    {
        $existing = $em->getRepository(Institution::class)->findOneBy(['name' => $institutionRequest->getName()]);

        $institution = $existing ?? new Institution();
        $institution->setName($institutionRequest->getName());
        $institution->setType($institutionRequest->getType());
        $institution->setCountry($institutionRequest->getCountry());
        $institution->setCity($institutionRequest->getCity());
        $institution->setAddress($institutionRequest->getAddress());
        $institution->setWebsite($institutionRequest->getWebsite());
        $institution->setVerified(true);
        $institution->setActive(true);

        if (!$existing) {
            $em->persist($institution);
        } else {
            $institution->setUpdatedAt(new \DateTimeImmutable());
        }

        $institutionRequest->setStatus(InstitutionRequestStatus::ACCEPTEE);
        $institutionRequest->setCreatedInstitution($institution);
    }
}
