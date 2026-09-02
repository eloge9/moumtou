<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\HttpUtils;

/**
 * Redirige chaque profil vers son espace après connexion (cahier des
 * charges : Talent → profil, Enseignant → espace enseignant, Recruteur →
 * recherche, Admin → tableau de bord). Si l'utilisateur avait été redirigé
 * vers /connexion depuis une page protégée précise, Symfony privilégie déjà
 * cette page d'origine (HttpUtils s'en charge) — cette logique ne s'applique
 * donc qu'en l'absence d'une telle destination mémorisée.
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly HttpUtils $httpUtils,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $user = $token->getUser();

        if ($request->hasSession() && ($targetPath = $request->getSession()->get('_security.main.target_path'))) {
            return new RedirectResponse($targetPath);
        }

        if (!$user instanceof User) {
            return $this->httpUtils->createRedirectResponse($request, 'app_home');
        }

        $route = match (true) {
            \in_array('ROLE_ADMIN', $user->getRoles(), true) => 'admin_dashboard',
            \in_array('ROLE_TEACHER', $user->getRoles(), true) => 'app_teacher_dashboard',
            \in_array('ROLE_RECRUITER', $user->getRoles(), true) => 'app_recruiter_search',
            \in_array('ROLE_TALENT', $user->getRoles(), true) => 'app_profile_show',
            default => 'app_home',
        };

        $params = 'app_profile_show' === $route ? ['slug' => $user->getSlug()] : [];

        return new RedirectResponse($this->urlGenerator->generate($route, $params));
    }
}
