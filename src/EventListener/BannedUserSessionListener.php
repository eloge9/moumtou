<?php

namespace App\EventListener;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Un compte peut être banni/suspendu par un administrateur alors que son
 * titulaire a déjà une session active : UserChecker ne s'exécute qu'à la
 * connexion, donc on referme ici toute session existante dès la requête
 * suivante (cahier des charges §32 : la sanction doit être réellement
 * appliquée, pas seulement bloquer les futures connexions). On vide le
 * token d'authentification : le ContextListener de Symfony, qui persiste
 * l'état de connexion en session sur kernel.response, écrit alors une
 * session anonyme — ce qui déconnecte réellement l'utilisateur.
 */
#[AsEventListener(event: 'kernel.request', priority: 0)]
class BannedUserSessionListener
{
    public function __construct(
        private readonly Security $security,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || UserStatus::ACTIF === $user->getStatus()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() === $this->pathOf('app_logout')) {
            return;
        }

        $this->tokenStorage->setToken(null);

        $message = match ($user->getStatus()) {
            UserStatus::BANNI => 'Ce compte a été banni de la plateforme.',
            UserStatus::SUPPRIME => 'Ce compte a été supprimé.',
            default => 'Ce compte est temporairement suspendu.',
        };
        $request->getSession()->getFlashBag()->add('erreur', $message);

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }

    private function pathOf(string $route): string
    {
        return $this->urlGenerator->generate($route);
    }
}
