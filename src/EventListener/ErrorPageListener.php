<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Twig\Environment;
use Twig\Error\LoaderError;

/**
 * Un refus d'accès (403) ou une page introuvable (404) fait partie du
 * fonctionnement normal de l'application — ce n'est pas un bug — et ne
 * doit donc pas afficher la trace de débogage technique de Symfony, y
 * compris en environnement dev. On affiche à la place un écran MOUMTOU
 * clair, quel que soit l'environnement ; toute autre erreur (bug réel)
 * continue de passer par le gestionnaire habituel de Symfony.
 */
#[AsEventListener(event: 'kernel.exception', priority: 0)]
class ErrorPageListener
{
    private const HANDLED_STATUS_CODES = [403, 404];

    public function __construct(private readonly Environment $twig)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof HttpExceptionInterface) {
            return;
        }

        $statusCode = $throwable->getStatusCode();
        if (!\in_array($statusCode, self::HANDLED_STATUS_CODES, true)) {
            return;
        }

        try {
            $content = $this->twig->render(sprintf('bundles/TwigBundle/Exception/error%d.html.twig', $statusCode));
        } catch (LoaderError) {
            return; // pas de template dédié : on laisse Symfony gérer normalement.
        }

        $event->setResponse(new Response($content, $statusCode, $throwable->getHeaders()));
    }
}
