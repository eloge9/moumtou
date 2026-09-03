<?php

namespace App\Twig;

use App\Entity\User;
use App\Enum\ContactRequestStatus;
use App\Repository\ContactRequestRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Compteur de demandes de contact en attente pour le talent connecté
 * (cahier des charges — FONCTIONNALITÉ 7 §16/§19), affiché dans le menu de
 * navigation ({@see \templates\components\_header.html.twig}). Une fonction
 * Twig plutôt qu'une variable passée par chaque contrôleur : l'en-tête est
 * inclus depuis des dizaines de pages différentes, il serait irréaliste
 * d'exiger que chacune calcule et transmette ce compteur.
 */
class RecruiterExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly ContactRequestRepository $contactRequestRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_contact_requests_count', $this->pendingContactRequestsCount(...)),
        ];
    }

    public function pendingContactRequestsCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->contactRequestRepository->count(['talent' => $user, 'status' => ContactRequestStatus::PENDING]);
    }
}
