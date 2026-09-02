<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\OAuth\OAuthProviderConfig;
use App\Security\OAuth\OAuthProviderRegistry;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Connexion/inscription via Google, Facebook ou LinkedIn (cahier des
 * charges §5.1, §8, §36). Flux OAuth2 "Authorization Code" générique,
 * implémenté directement avec symfony/http-client (déjà présent) plutôt
 * qu'un bundle tiers, pour ne dépendre d'aucune clé d'API tant qu'elle
 * n'est pas configurée.
 *
 * Tant que GOOGLE_OAUTH_CLIENT_ID / _SECRET (ou l'équivalent Facebook /
 * LinkedIn) ne sont pas renseignés dans .env.local, /connexion/{provider}
 * affiche un message explicite au lieu de planter — voir .env.example.
 */
class OAuthController extends AbstractController
{
    #[Route('/connexion/{provider}', name: 'app_oauth_start', requirements: ['provider' => 'google|facebook|linkedin'])]
    public function start(string $provider, Request $request, OAuthProviderRegistry $registry, UrlGeneratorInterface $urlGenerator): Response
    {
        $config = $registry->get($provider);

        if (!$config || !$config->isConfigured()) {
            $this->addFlash('erreur', sprintf(
                'La connexion via %s n\'est pas encore configurée sur cette instance (variables GOOGLE_OAUTH_CLIENT_ID/SECRET et équivalents dans .env.local).',
                $config->label ?? $provider,
            ));

            return $this->redirectToRoute('app_login');
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('oauth_state', $state);
        $request->getSession()->set('oauth_provider', $provider);

        $params = [
            'client_id' => $config->clientId,
            'redirect_uri' => $urlGenerator->generate('app_oauth_callback', ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL),
            'response_type' => 'code',
            'scope' => $config->scope,
            'state' => $state,
        ];

        return new RedirectResponse($config->authorizeUrl.'?'.http_build_query($params));
    }

    #[Route('/connexion/{provider}/callback', name: 'app_oauth_callback', requirements: ['provider' => 'google|facebook|linkedin'])]
    public function callback(
        string $provider,
        Request $request,
        OAuthProviderRegistry $registry,
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $em,
        SlugGenerator $slugGenerator,
        Security $security,
    ): Response {
        $config = $registry->get($provider);
        if (!$config || !$config->isConfigured()) {
            throw $this->createNotFoundException();
        }

        $session = $request->getSession();
        $expectedState = $session->get('oauth_state');
        $session->remove('oauth_state');

        if (!$expectedState || $request->query->get('state') !== $expectedState) {
            $this->addFlash('erreur', 'La connexion a échoué (session expirée). Merci de réessayer.');

            return $this->redirectToRoute('app_login');
        }

        $code = $request->query->get('code');
        if (!$code) {
            $this->addFlash('erreur', 'Connexion annulée.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $userInfo = $this->exchangeCodeForUserInfo($config, $code, $urlGenerator);
        } catch (HttpClientExceptionInterface|\RuntimeException) {
            $this->addFlash('erreur', 'Impossible de contacter le fournisseur de connexion. Merci de réessayer.');

            return $this->redirectToRoute('app_login');
        }

        $mapped = $config->mapUserInfo($userInfo);
        $idField = $provider.'Id';
        $getIdMethod = 'get'.ucfirst($idField);

        $user = $em->getRepository(User::class)->findOneBy([$idField => $mapped['id']]);

        if (!$user && $mapped['email']) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $mapped['email']]);
            if ($user) {
                $user->{'set'.ucfirst($idField)}($mapped['id']);
            }
        }

        if (!$user) {
            if (!$mapped['email']) {
                $this->addFlash('erreur', sprintf('%s n\'a pas transmis d\'adresse e-mail : impossible de créer le compte.', $config->label));

                return $this->redirectToRoute('app_login');
            }

            $user = new User();
            $user->setEmail($mapped['email']);
            $user->setFirstName($mapped['firstName']);
            $user->setLastName($mapped['lastName']);
            $user->setPhone('');
            $user->setRoles(['ROLE_TALENT']);
            $user->setStatus(UserStatus::ACTIF);
            $user->setEmailVerified(true); // le fournisseur a déjà vérifié l'adresse
            $user->setSlug($slugGenerator->generateUnique($user->getFullName(), User::class));
            $user->setPassword(bin2hex(random_bytes(32))); // jamais utilisé : authentification uniquement via ce fournisseur
            $user->{'set'.ucfirst($idField)}($mapped['id']);
            $em->persist($user);
        }

        if (UserStatus::ACTIF !== $user->getStatus()) {
            $this->addFlash('erreur', 'Ce compte n\'est pas accessible.');

            return $this->redirectToRoute('app_login');
        }

        $em->flush();

        $security->login($user, 'form_login', 'main');

        return $this->redirectToRoute('app_home');
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCodeForUserInfo(OAuthProviderConfig $config, string $code, UrlGeneratorInterface $urlGenerator): array
    {
        $client = HttpClient::create();

        $tokenResponse = $client->request('POST', $config->tokenUrl, [
            'body' => [
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $urlGenerator->generate('app_oauth_callback', ['provider' => $config->name], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
            'headers' => ['Accept' => 'application/json'],
        ]);

        $tokenData = $tokenResponse->toArray(false);
        $accessToken = $tokenData['access_token'] ?? null;
        if (!$accessToken) {
            throw new \RuntimeException('Aucun jeton d\'accès reçu du fournisseur OAuth.');
        }

        $userInfoResponse = $client->request('GET', $config->userInfoUrl, [
            'auth_bearer' => $accessToken,
            'headers' => ['Accept' => 'application/json'],
        ]);

        return $userInfoResponse->toArray(false);
    }
}
