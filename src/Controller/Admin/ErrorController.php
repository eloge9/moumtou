<?php

namespace App\Controller\Admin;

use App\Repository\ErrorLogRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord "Monitoring" (cahier des charges — FONCTIONNALITÉ 18
 * §24/§25/§26/§27) : répond à "MOUMTOU fonctionne-t-il correctement en ce
 * moment ?" — état des services, compteurs d'erreurs, endpoints les plus
 * problématiques, liste et détail des erreurs récentes. Jamais de secret ni
 * de trace complète affichés (voir {@see \App\Entity\ErrorLog}).
 */
#[IsGranted('ROLE_ADMIN')]
class ErrorController extends AbstractController
{
    #[Route('/admin/monitoring', name: 'admin_monitoring')]
    public function index(Request $request, ErrorLogRepository $repository, Connection $connection): Response
    {
        $since24h = (new \DateTimeImmutable())->modify('-24 hours');

        $statusCode = $request->query->get('status_code') ? (int) $request->query->get('status_code') : null;
        $level = $request->query->get('level') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $result = $repository->search($statusCode, $level, $page);

        $databaseOk = true;
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $databaseOk = false;
        }

        return $this->render('admin/monitoring.html.twig', [
            'adminNav' => 'monitoring',
            'summary' => $repository->summary(),
            'statusCodeBreakdown' => $repository->countByStatusCodeSince($since24h),
            'mostProblematicPaths' => $repository->mostProblematicPathsSince($since24h),
            'databaseOk' => $databaseOk,
            'errors' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pageCount' => (int) ceil($result['total'] / ErrorLogRepository::PER_PAGE),
            'statusCode' => $statusCode,
            'level' => $level,
        ]);
    }

    #[Route('/admin/monitoring/{id}', name: 'admin_monitoring_show', requirements: ['id' => '\d+'])]
    public function show(int $id, ErrorLogRepository $repository): Response
    {
        $error = $repository->find($id);
        if (!$error) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/monitoring_show.html.twig', [
            'adminNav' => 'monitoring',
            'error' => $error,
        ]);
    }
}
