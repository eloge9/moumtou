<?php

namespace App\Controller;

use App\Entity\ContactRequest;
use App\Enum\ContactRequestStatus;
use App\Enum\NotificationType;
use App\Security\ContactRequestMailer;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cahier des charges — FONCTIONNALITÉ 7 §16 : côté talent, gérer les
 * demandes de contact reçues des recruteurs (accepter/refuser).
 */
#[IsGranted('ROLE_TALENT')]
class TalentContactController extends AbstractController
{
    #[Route('/mon-espace-talent/demandes', name: 'app_talent_contact_requests')]
    public function index(EntityManagerInterface $em): Response
    {
        $requests = $em->getRepository(ContactRequest::class)->createQueryBuilder('c')
            ->join('c.recruiter', 'r')->addSelect('r')
            ->andWhere('c.talent = :talent')->setParameter('talent', $this->getUser())
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()->getResult();

        return $this->render('talent/contact_requests.html.twig', [
            'active_nav' => 'profil',
            'requests' => $requests,
        ]);
    }

    #[Route('/mon-espace-talent/demandes/{id}/accepter', name: 'app_talent_contact_request_accept', methods: ['POST'])]
    public function accept(int $id, Request $request, EntityManagerInterface $em, ContactRequestMailer $mailer, NotificationService $notificationService): Response
    {
        return $this->decide($id, $request, $em, $mailer, $notificationService, ContactRequestStatus::ACCEPTED, 'accepter-demande-');
    }

    #[Route('/mon-espace-talent/demandes/{id}/refuser', name: 'app_talent_contact_request_refuse', methods: ['POST'])]
    public function refuse(int $id, Request $request, EntityManagerInterface $em, ContactRequestMailer $mailer, NotificationService $notificationService): Response
    {
        return $this->decide($id, $request, $em, $mailer, $notificationService, ContactRequestStatus::REFUSED, 'refuser-demande-');
    }

    private function decide(int $id, Request $request, EntityManagerInterface $em, ContactRequestMailer $mailer, NotificationService $notificationService, ContactRequestStatus $decision, string $tokenPrefix): Response
    {
        $contactRequest = $em->getRepository(ContactRequest::class)->find($id);
        if (!$contactRequest || $contactRequest->getTalent() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid($tokenPrefix.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        if (ContactRequestStatus::PENDING !== $contactRequest->getStatus()) {
            $this->addFlash('erreur', 'Cette demande a déjà été traitée.');

            return $this->redirectToRoute('app_talent_contact_requests');
        }

        $contactRequest->setStatus($decision);
        $contactRequest->setRespondedAt(new \DateTimeImmutable());
        $em->flush();

        $mailer->notifyRecruiterOfDecision($contactRequest);
        $notificationService->notify(
            $contactRequest->getRecruiter(),
            ContactRequestStatus::ACCEPTED === $decision ? NotificationType::CONTACT_REQUEST_ACCEPTED : NotificationType::CONTACT_REQUEST_REFUSED,
            ContactRequestStatus::ACCEPTED === $decision ? 'Demande de contact acceptée' : 'Demande de contact refusée',
            \sprintf('%s a %s votre demande de contact.', $contactRequest->getTalent()->getFullName(), ContactRequestStatus::ACCEPTED === $decision ? 'accepté' : 'refusé'),
            $this->generateUrl('app_recruiter_contact_requests'),
            sendEmail: false,
        );

        $this->addFlash('succes', ContactRequestStatus::ACCEPTED === $decision ? 'Demande acceptée.' : 'Demande refusée.');

        return $this->redirectToRoute('app_talent_contact_requests');
    }
}
