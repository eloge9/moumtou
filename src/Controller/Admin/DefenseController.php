<?php

namespace App\Controller\Admin;

use App\Entity\Defense;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Enum\DefenseStatus;
use App\Service\AdminAuditLogger;
use App\Service\DefenseValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liste des soutenances pour l'administrateur (cahier des charges §34).
 * Le détail (jury, validations, résultat, historique) reste sur la page
 * "Examiner un projet" déjà existante ({@see \App\Controller\Admin\ProjectController::show()})
 * — pas de doublon d'écran, seulement une entrée dédiée pour filtrer par
 * statut de soutenance.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/soutenances')]
class DefenseController extends AbstractController
{
    #[Route('', name: 'admin_defenses')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $filter = (string) $request->query->get('filtre', 'toutes');

        $qb = $em->getRepository(Defense::class)->createQueryBuilder('d')
            ->leftJoin('d.project', 'p')->addSelect('p')
            ->leftJoin('p.owner', 'owner')->addSelect('owner')
            ->orderBy('d.date', 'DESC');

        match ($filter) {
            'a_venir' => $qb->andWhere('d.status = :status')->setParameter('status', DefenseStatus::ANNONCEE),
            'realisees' => $qb->andWhere('d.status = :status')->setParameter('status', DefenseStatus::REALISEE),
            'verifiees' => $qb->andWhere('d.status = :status')->setParameter('status', DefenseStatus::VERIFIEE),
            'reportees' => $qb->andWhere('d.status = :status')->setParameter('status', DefenseStatus::REPORTEE),
            'annulees' => $qb->andWhere('d.status = :status')->setParameter('status', DefenseStatus::ANNULEE),
            default => null,
        };

        $defenses = $qb->getQuery()->getResult();

        // "En validation" = réalisée avec au moins 1 confirmation mais pas encore vérifiée.
        if ('en_validation' === $filter) {
            $defenses = array_values(array_filter($defenses, fn (Defense $d) => DefenseStatus::REALISEE === $d->getStatus() && $d->getValidationCount() > 0));
        }

        return $this->render('admin/defenses.html.twig', [
            'adminNav' => 'defenses',
            'defenses' => $defenses,
            'filter' => $filter,
        ]);
    }

    /**
     * Vérification directe d'une soutenance par un administrateur, sans
     * attendre les 2 confirmations du jury (cahier des charges — gestion des
     * soutenances §25) : utile quand le jury ne peut pas confirmer lui-même
     * (membre externe jamais inscrit, soutenance ancienne à régulariser…).
     * Volontairement sans condition de statut préalable (même une soutenance
     * encore « annoncée » peut être vérifiée directement par un
     * administrateur) — seule une soutenance annulée ou déjà vérifiée est
     * refusée.
     */
    #[Route('/{id}/verifier', name: 'admin_defense_verify', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function verify(int $id, Request $request, EntityManagerInterface $em, DefenseValidator $defenseValidator, AdminAuditLogger $auditLogger): Response
    {
        $defense = $em->getRepository(Defense::class)->find($id);
        if (!$defense) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('verifier-soutenance-admin-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $redirectResponse = $this->redirectToRoute('admin_project_show', ['id' => $defense->getProject()->getId()]);

        if (DefenseStatus::ANNULEE === $defense->getStatus()) {
            $this->addFlash('erreur', 'Une soutenance annulée ne peut pas être vérifiée.');

            return $redirectResponse;
        }
        if (DefenseStatus::VERIFIEE === $defense->getStatus()) {
            $this->addFlash('erreur', 'Cette soutenance est déjà vérifiée.');

            return $redirectResponse;
        }

        $projectName = $defense->getProject()->getName();
        $defenseValidator->forceVerifyByAdmin($defense);

        /** @var User $admin */
        $admin = $this->getUser();
        $auditLogger->log($admin, AdminAuditAction::DEFENSE_VERIFIED_BY_ADMIN, 'Defense', $defense->getId(), $projectName);

        $this->addFlash('succes', 'La soutenance a été vérifiée.');

        return $redirectResponse;
    }
}
