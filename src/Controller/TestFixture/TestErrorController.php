<?php

namespace App\Controller\TestFixture;

use Doctrine\DBAL\Exception\DatabaseRequired;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Déclenche une exception réelle et non gérée, uniquement utilisable en
 * environnement de test (cahier des charges — FONCTIONNALITÉ 18 §38) : sert
 * à vérifier de bout en bout le gestionnaire global d'exceptions
 * ({@see \App\EventListener\ErrorPageListener}) sur une véritable erreur
 * serveur contrôlée, sans jamais exposer ce levier en dev/production (404
 * immédiat en dehors de l'environnement de test).
 */
class TestErrorController extends AbstractController
{
    public function __construct(private readonly string $environment)
    {
    }

    #[Route('/_test/throw-error', name: 'app_test_throw_error')]
    public function __invoke(Request $request): Response
    {
        if ('test' !== $this->environment) {
            throw new NotFoundHttpException();
        }

        if ('critical' === $request->query->get('type')) {
            // Exception DBAL réelle (implémente Doctrine\DBAL\Exception) :
            // sert à vérifier la classification CRITICAL, sans dépendre
            // d'une vraie coupure de base de données.
            throw DatabaseRequired::new('test');
        }

        throw new \RuntimeException('Erreur de test déclenchée volontairement.');
    }
}
