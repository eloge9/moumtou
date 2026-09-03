<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Centre de notifications (cahier des charges — FONCTIONNALITÉ 8 §9/§12) :
 * un utilisateur ne voit et ne modifie jamais que ses propres notifications
 * (§29 — jamais d'IDOR).
 */
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    #[Route('/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(Request $request, NotificationRepository $notificationRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, (int) ($request->query->get('page') ?: 1));

        $result = $notificationRepository->findPage($user, $page);

        return $this->render('notification/index.html.twig', [
            'notifications' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pageCount' => (int) ceil($result['total'] / NotificationRepository::PER_PAGE),
        ]);
    }

    /**
     * Ouvre une notification : la marque lue puis redirige vers l'action
     * concernée (cahier §12 : marquage automatique à l'ouverture), ou vers
     * le centre de notifications si aucun lien n'est associé.
     */
    #[Route('/notifications/{id}/ouvrir', name: 'app_notification_open', methods: ['GET'])]
    public function open(int $id, EntityManagerInterface $em): Response
    {
        $notification = $this->findOwnNotificationOr404($id, $em);

        $notification->markAsRead();
        $em->flush();

        return $this->redirect($notification->getActionUrl() ?: $this->generateUrl('app_notifications'));
    }

    #[Route('/notifications/{id}/lire', name: 'app_notification_mark_read', methods: ['POST'])]
    public function markRead(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $notification = $this->findOwnNotificationOr404($id, $em);

        if (!$this->isCsrfTokenValid('notification-lire-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $notification->markAsRead();
        $em->flush();

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_notifications'));
    }

    #[Route('/notifications/tout-lire', name: 'app_notification_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request, NotificationRepository $notificationRepository): Response
    {
        if (!$this->isCsrfTokenValid('notification-tout-lire', $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $notificationRepository->markAllAsRead($user);

        $this->addFlash('succes', 'Toutes les notifications ont été marquées comme lues.');

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_notifications'));
    }

    #[Route('/notifications/{id}/supprimer', name: 'app_notification_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $notification = $this->findOwnNotificationOr404($id, $em);

        if (!$this->isCsrfTokenValid('notification-supprimer-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $em->remove($notification);
        $em->flush();

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_notifications'));
    }

    #[Route('/notifications/preferences', name: 'app_notification_preferences', methods: ['GET', 'POST'])]
    public function preferences(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('notification-preferences', $request->request->get('_csrf_token'))) {
                throw new InvalidCsrfTokenException();
            }

            foreach (NotificationCategory::cases() as $category) {
                // Cahier §24 : la sécurité reste toujours active, quoi que
                // le formulaire soumette pour cette catégorie.
                if ($category->isMandatory()) {
                    continue;
                }

                $preference = $em->getRepository(NotificationPreference::class)->findOneBy(['user' => $user, 'category' => $category]);
                if (!$preference) {
                    $preference = new NotificationPreference();
                    $preference->setUser($user);
                    $preference->setCategory($category);
                    $em->persist($preference);
                }

                $preference->setInAppEnabled($request->request->getBoolean('in_app_'.$category->value));
                $preference->setEmailEnabled($request->request->getBoolean('email_'.$category->value));
            }

            $em->flush();

            $this->addFlash('succes', 'Vos préférences de notification ont été enregistrées.');

            return $this->redirectToRoute('app_notification_preferences');
        }

        $preferenceRepository = $em->getRepository(NotificationPreference::class);
        $preferences = [];
        foreach (NotificationCategory::cases() as $category) {
            $existing = $preferenceRepository->findOneBy(['user' => $user, 'category' => $category]);
            $preferences[$category->value] = [
                'inApp' => $existing ? $existing->isInAppEnabled() : true,
                'email' => $existing ? $existing->isEmailEnabled() : $category->defaultEmailEnabled(),
            ];
        }

        return $this->render('notification/preferences.html.twig', [
            'categories' => NotificationCategory::cases(),
            'preferences' => $preferences,
        ]);
    }

    private function findOwnNotificationOr404(int $id, EntityManagerInterface $em): Notification
    {
        $notification = $em->getRepository(Notification::class)->find($id);
        if (!$notification || $notification->getRecipient() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $notification;
    }
}
