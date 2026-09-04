<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifiant de corrélation (cahier des charges — FONCTIONNALITÉ 18 §7) :
 * généré côté serveur (jamais fourni par le client — cette application est
 * rendue côté serveur, il n'existe pas de "frontend" séparé qui pourrait le
 * produire en amont), attaché à la requête pour que {@see RequestIdProcessor}
 * l'ajoute automatiquement à chaque ligne de log, exposé en en-tête de
 * réponse `X-Request-Id`, et affiché à l'utilisateur uniquement sur les
 * pages d'erreur ("Référence : ...") pour qu'il puisse la communiquer à
 * l'administration.
 */
class RequestIdListener implements EventSubscriberInterface
{
    public const ATTRIBUTE = '_request_id';
    public const HEADER = 'X-Request-Id';

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // 16 caractères hexadécimaux : assez court pour être communiqué
        // oralement/par e-mail par un utilisateur, assez long pour être
        // pratiquement unique.
        $event->getRequest()->attributes->set(self::ATTRIBUTE, strtoupper(bin2hex(random_bytes(8))));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if ($requestId) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité élevée : doit s'exécuter avant tout code métier ou
            // journal susceptible d'avoir besoin de l'identifiant.
            KernelEvents::REQUEST => ['onKernelRequest', 10000],
            KernelEvents::RESPONSE => ['onKernelResponse', -10000],
        ];
    }
}
