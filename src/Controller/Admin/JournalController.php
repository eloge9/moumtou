<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Repository\AdminAuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Journal d'administration (cahier des charges — FONCTIONNALITÉ 9 §40-43) :
 * trace filtrable de toute action administrative significative, alimentée
 * par {@see \App\Service\AdminAuditLogger}. Lecture seule — aucune route
 * d'édition ou de suppression n'existe pour ce journal.
 */
#[IsGranted('ROLE_ADMIN')]
class JournalController extends AbstractController
{
    #[Route('/admin/journal', name: 'admin_journal')]
    public function index(Request $request, EntityManagerInterface $em, AdminAuditLogRepository $repository): Response
    {
        $adminId = (int) $request->query->get('admin', 0);
        $admin = $adminId ? $em->getRepository(User::class)->find($adminId) : null;
        $action = AdminAuditAction::tryFrom((string) $request->query->get('action', ''));
        $targetType = trim((string) $request->query->get('target_type', ''));
        $dateFrom = trim((string) $request->query->get('date_from', ''));
        $dateTo = trim((string) $request->query->get('date_to', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $repository->search($admin, $action, $targetType ?: null, $dateFrom ?: null, $dateTo ?: null, $page);

        $admins = $em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/journal.html.twig', [
            'adminNav' => 'journal',
            'logs' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pageCount' => (int) ceil($result['total'] / AdminAuditLogRepository::PER_PAGE),
            'admins' => $admins,
            'selectedAdmin' => $admin,
            'selectedAction' => $action,
            'targetType' => $targetType,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'targetTypes' => $repository->distinctTargetTypes(),
            'allActions' => AdminAuditAction::cases(),
        ]);
    }
}
