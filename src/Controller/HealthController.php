<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôle de santé (cahier des charges — FONCTIONNALITÉ 18 §22) : public,
 * sans authentification (c'est la convention pour un endpoint consommé par
 * un outil de supervision externe/équilibreur de charge), mais ne retourne
 * jamais rien d'interne — ni identifiants, ni chemin serveur, ni version.
 */
class HealthController extends AbstractController
{
    public function __construct(private readonly string $projectUploadsDirectory)
    {
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function index(Connection $connection): JsonResponse
    {
        $database = $this->checkDatabase($connection);
        $storage = $this->checkStorage();

        $allOk = 'ok' === $database && 'ok' === $storage;

        return new JsonResponse([
            'status' => $allOk ? 'ok' : 'degraded',
            'database' => $database,
            'storage' => $storage,
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], $allOk ? 200 : 503);
    }

    private function checkDatabase(Connection $connection): string
    {
        try {
            $connection->executeQuery('SELECT 1');

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkStorage(): string
    {
        $parent = \dirname($this->projectUploadsDirectory);

        return is_dir($parent) && is_writable($parent) ? 'ok' : 'error';
    }
}
