<?php

namespace App\Controller\Admin;

use App\Service\Backup\BackupHistoryLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord "Sauvegardes" (cahier des charges — FONCTIONNALITÉ 16
 * §16) : consultation seule de l'historique — aucun bouton ne déclenche de
 * sauvegarde/restauration depuis le web (§10/§20 : ces opérations restent
 * strictement réservées à la CLI, jamais exposées à une requête HTTP, même
 * pour un administrateur).
 */
#[IsGranted('ROLE_ADMIN')]
class BackupController extends AbstractController
{
    #[Route('/admin/sauvegardes', name: 'admin_backups')]
    public function index(BackupHistoryLogger $historyLogger): Response
    {
        $entries = $historyLogger->recent(50);

        $lastByType = [];
        foreach ($entries as $entry) {
            if (!isset($lastByType[$entry['type']])) {
                $lastByType[$entry['type']] = $entry;
            }
        }
        $lastRestore = null;
        foreach ($entries as $entry) {
            if ('restore' === $entry['kind']) {
                $lastRestore = $entry;
                break;
            }
        }

        return $this->render('admin/backups.html.twig', [
            'adminNav' => 'backups',
            'entries' => $entries,
            'lastByType' => $lastByType,
            'lastRestore' => $lastRestore,
        ]);
    }
}
