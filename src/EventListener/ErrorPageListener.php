<?php

namespace App\EventListener;

use App\Entity\ErrorLog;
use App\Entity\User;
use App\Service\CriticalErrorAlerter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Twig\Environment;
use Twig\Error\LoaderError;

/**
 * Gestion globale des exceptions (cahier des charges — FONCTIONNALITÉ 18
 * §3/§11) : point d'entrée unique pour toute erreur non gérée localement.
 *
 * - 403/404 : flux normal de l'application (gabarits dédiés déjà existants
 *   depuis les fonctionnalités précédentes) — jamais journalisés comme des
 *   erreurs, jamais persistés, jamais alertés.
 * - Autres 4xx sans gabarit dédié (400/401/405/409/422/429) : anomalie
 *   côté client, journalisée en WARNING, jamais un bug serveur.
 * - 5xx (ou toute exception non-HTTP, donc un vrai bug) : journalisé en
 *   ERROR/CRITICAL avec l'identifiant de corrélation, persisté dans
 *   {@see ErrorLog} pour le tableau de bord admin, et alerté si critique
 *   (cahier §33/§34). En développement (`kernel.debug`), la page de
 *   débogage habituelle de Symfony reste affichée — seule la journalisation
 *   est faite dans ce cas, pour ne jamais gêner le développement local.
 *
 * Aucun détail technique (trace, requête SQL, chemin serveur) n'atteint
 * jamais la réponse envoyée à l'utilisateur (cahier §6) : ces informations
 * restent uniquement dans les journaux Monolog (contexte de l'exception).
 */
#[AsEventListener(event: 'kernel.exception', priority: 0)]
class ErrorPageListener
{
    private const DEDICATED_TEMPLATES = [403, 404];

    /** Routes dont les erreurs doivent être renvoyées au format JSON (cahier §4). */
    private const JSON_ROUTES = ['app_search_suggestions'];

    public function __construct(
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly CriticalErrorAlerter $alerter,
        private readonly Security $security,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $throwable = $event->getThrowable();
        $request = $event->getRequest();
        $requestId = (string) $request->attributes->get(RequestIdListener::ATTRIBUTE, '');
        $statusCode = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        $isServerError = $statusCode >= 500;

        if ($isServerError) {
            $this->recordServerError($throwable, $request, $requestId, $statusCode);
        } elseif (!\in_array($statusCode, self::DEDICATED_TEMPLATES, true)) {
            $this->logger->warning(sprintf('[%s] %s %s -> HTTP %d', $requestId, $request->getMethod(), $request->getPathInfo(), $statusCode), [
                'request_id' => $requestId,
                'status_code' => $statusCode,
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
            ]);
        }

        if ($this->expectsJson($request)) {
            $event->setResponse($this->jsonErrorResponse($statusCode, $requestId));

            return;
        }

        if (\in_array($statusCode, self::DEDICATED_TEMPLATES, true)) {
            $this->renderDedicatedTemplate($event, $statusCode, $throwable);

            return;
        }

        if ($isServerError && $this->debug) {
            return; // laisse Symfony afficher sa page de débogage habituelle en développement
        }

        $this->renderFallback($event, $statusCode, $throwable, $requestId);
    }

    private function recordServerError(\Throwable $throwable, Request $request, string $requestId, int $statusCode): void
    {
        $level = $this->isCritical($throwable) ? 'critical' : 'error';

        $context = [
            'request_id' => $requestId,
            'status_code' => $statusCode,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            // Monolog place l'exception (avec sa trace complète) dans le
            // contexte du journal, jamais dans la réponse HTTP (cahier §6).
            'exception' => $throwable,
        ];
        $user = $this->currentUser();
        if ($user) {
            $context['user_id'] = $user->getId();
        }

        $message = sprintf('[%s] %s %s -> %d: %s', $requestId, $request->getMethod(), $request->getPathInfo(), $statusCode, $throwable->getMessage());
        if ('critical' === $level) {
            $this->logger->critical($message, $context);
        } else {
            $this->logger->error($message, $context);
        }

        try {
            $errorLog = new ErrorLog();
            $errorLog->setRequestId($requestId ?: 'N-A');
            $errorLog->setLevel($level);
            $errorLog->setStatusCode($statusCode);
            $errorLog->setMethod($request->getMethod());
            $errorLog->setPath($request->getPathInfo());
            $errorLog->setExceptionClass($throwable::class);
            $errorLog->setMessage($throwable->getMessage());
            $errorLog->setUser($user);
            $this->em->persist($errorLog);
            $this->em->flush();
        } catch (\Throwable) {
            // Si la base est elle-même indisponible (cahier §12), ne jamais
            // faire échouer la gestion d'erreur : le journal Monolog
            // ci-dessus reste la trace de référence dans ce cas.
        }

        if ('critical' === $level) {
            try {
                $this->alerter->alert($throwable::class, $throwable->getMessage(), $request->getPathInfo(), $requestId);
            } catch (\Throwable) {
                // Idem : l'alerte ne doit jamais faire planter la gestion d'erreur.
            }
        }
    }

    /**
     * CRITICAL réservé aux signaux clairs d'indisponibilité d'un service
     * dont dépend MOUMTOU (base de données…) — cahier §8/§33. Un bug
     * applicatif "ordinaire" (ex. TypeError) reste en ERROR : le service
     * dans son ensemble fonctionne toujours pour les autres visiteurs.
     */
    private function isCritical(\Throwable $throwable): bool
    {
        return $throwable instanceof \Doctrine\DBAL\Exception;
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function expectsJson(Request $request): bool
    {
        if (\in_array($request->attributes->get('_route'), self::JSON_ROUTES, true)) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    private function jsonErrorResponse(int $statusCode, string $requestId): JsonResponse
    {
        [, $message] = $this->genericMessageFor($statusCode);

        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => $this->errorCodeFor($statusCode),
                'message' => $message,
            ],
            'request_id' => $requestId,
        ], $statusCode);
    }

    private function errorCodeFor(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'TOO_MANY_REQUESTS',
            503 => 'SERVICE_UNAVAILABLE',
            default => $statusCode >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_ERROR',
        };
    }

    /** @return array{0: string, 1: string} */
    private function genericMessageFor(int $statusCode): array
    {
        return match ($statusCode) {
            400 => ['Requête invalide', 'La requête envoyée n\'a pas pu être comprise.'],
            401 => ['Connexion requise', 'Vous devez être connecté pour accéder à cette page.'],
            403 => ['Accès refusé', 'Vous n\'avez pas les droits nécessaires pour accéder à cette page.'],
            404 => ['Page introuvable', 'Le contenu recherché n\'existe pas ou n\'est plus disponible.'],
            405 => ['Méthode non autorisée', 'Cette action n\'est pas autorisée sur cette page.'],
            409 => ['Conflit', 'Cette action ne peut pas être effectuée dans l\'état actuel.'],
            422 => ['Données invalides', 'Les informations envoyées ne sont pas valides.'],
            429 => ['Trop de requêtes', 'Vous avez effectué trop de requêtes. Merci de patienter avant de réessayer.'],
            503 => ['Service indisponible', 'MOUMTOU est temporairement indisponible. Merci de réessayer dans quelques instants.'],
            default => ['Une erreur est survenue', 'Un problème technique est survenu de notre côté.'],
        };
    }

    private function renderDedicatedTemplate(ExceptionEvent $event, int $statusCode, \Throwable $throwable): void
    {
        try {
            $content = $this->twig->render(sprintf('bundles/TwigBundle/Exception/error%d.html.twig', $statusCode));
        } catch (LoaderError) {
            return; // pas de gabarit dédié : on laisse Symfony gérer normalement.
        }

        $event->setResponse(new Response($content, $statusCode, $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : []));
    }

    private function renderFallback(ExceptionEvent $event, int $statusCode, \Throwable $throwable, string $requestId): void
    {
        if ($statusCode >= 500) {
            $content = $this->twig->render('bundles/TwigBundle/Exception/error500.html.twig', ['requestId' => $requestId]);
        } else {
            [$title, $message] = $this->genericMessageFor($statusCode);
            $content = $this->twig->render('bundles/TwigBundle/Exception/error_generic.html.twig', [
                'statusCode' => $statusCode,
                'title' => $title,
                'message' => $message,
                'requestId' => $requestId,
            ]);
        }

        $event->setResponse(new Response($content, $statusCode, $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : []));
    }
}
