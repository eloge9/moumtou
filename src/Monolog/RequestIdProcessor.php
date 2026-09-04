<?php

namespace App\Monolog;

use App\EventListener\RequestIdListener;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ajoute automatiquement l'identifiant de corrélation de la requête en
 * cours à chaque ligne de log (cahier des charges — FONCTIONNALITÉ 18
 * §7/§9) : évite de devoir le passer manuellement à chaque appel du logger
 * dans les contrôleurs/services.
 */
class RequestIdProcessor
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        $requestId = $request?->attributes->get(RequestIdListener::ATTRIBUTE);
        if ($requestId) {
            $record->extra['request_id'] = $requestId;
        }

        return $record;
    }
}
