<?php

namespace App\Controller\Admin;

use App\Entity\Defense;
use App\Enum\DefenseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
}
