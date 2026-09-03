<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationPreferenceRepository;
use App\Security\NotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Point d'entrée central des notifications (cahier des charges —
 * FONCTIONNALITÉ 8 §4/§27) : tout événement de la plateforme qui doit
 * informer un utilisateur passe par ici plutôt que par de la logique
 * dispersée dans chaque contrôleur.
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPreferenceRepository $preferenceRepository,
        private readonly NotificationMailer $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param bool $sendEmail à mettre à false lorsqu'un mailer dédié à
     *                        l'événement a déjà envoyé l'e-mail correspondant
     *                        (demande de contact, invitation jury, sanction) —
     *                        évite un e-mail en double, la notification
     *                        interne reste créée normalement.
     */
    public function notify(
        User $recipient,
        NotificationType $type,
        string $title,
        string $message,
        ?string $actionPath = null,
        bool $sendEmail = true,
    ): ?Notification {
        $preferences = $this->preferenceRepository->resolve($recipient, $type->category());

        $notification = null;
        if ($preferences['inApp']) {
            $notification = new Notification();
            $notification->setRecipient($recipient);
            $notification->setType($type);
            $notification->setTitle($title);
            $notification->setMessage($message);
            $notification->setActionUrl($actionPath);
            $this->em->persist($notification);
            $this->em->flush();
        }

        if ($sendEmail && $preferences['email']) {
            // Le chemin fourni par l'appelant est déjà une URL relative
            // générée par Symfony (generateUrl) : on la rend absolue pour
            // l'e-mail sans revalider de route ici.
            $absoluteUrl = $actionPath ? $this->toAbsoluteUrl($actionPath) : null;
            $this->mailer->send($recipient, $title, $message, $absoluteUrl);
        }

        return $notification;
    }

    private function toAbsoluteUrl(string $path): string
    {
        $base = rtrim($this->urlGenerator->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), '/');

        return $base.$path;
    }
}
