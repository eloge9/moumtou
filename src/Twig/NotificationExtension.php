<?php

namespace App\Twig;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Compteur non lu et aperçu des dernières notifications de l'utilisateur
 * connecté (cahier des charges — FONCTIONNALITÉ 8 §10/§11), utilisés
 * directement dans l'en-tête global — même principe que
 * {@see \App\Twig\RecruiterExtension::pendingContactRequestsCount()}.
 */
class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', $this->unreadCount(...)),
            new TwigFunction('recent_notifications', $this->recentNotifications(...)),
        ];
    }

    public function unreadCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notificationRepository->countUnread($user) : 0;
    }

    /**
     * @return Notification[]
     */
    public function recentNotifications(int $limit = 5): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notificationRepository->findRecent($user, $limit) : [];
    }
}
