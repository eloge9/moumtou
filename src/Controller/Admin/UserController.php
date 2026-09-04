<?php

namespace App\Controller\Admin;

use App\Entity\Sanction;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Enum\ReportTargetType;
use App\Enum\UserStatus;
use App\Repository\VerificationRequestRepository;
use App\Service\AccountDeletionService;
use App\Service\AdminAuditLogger;
use App\Service\SanctionApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion directe des comptes (cahier des charges §4.6/§29 : "gérer les
 * utilisateurs"), indépendamment du circuit de signalement — un
 * administrateur doit pouvoir retrouver n'importe quel compte et le
 * suspendre/bannir/réactiver sans attendre qu'il soit signalé.
 */
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/admin/utilisateurs', name: 'admin_users')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $role = (string) $request->query->get('role', '');
        $status = UserStatus::tryFrom((string) $request->query->get('status', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $qb = $em->getRepository(User::class)->createQueryBuilder('u')
            // Association OneToOne côté inverse : toujours chargée par
            // Doctrine, une requête par utilisateur sans cette jointure
            // (cahier — FONCTIONNALITÉ 17 §4/§16, liste admin paginée).
            ->leftJoin('u.recruiterProfile', 'recruiterProfile')->addSelect('recruiterProfile')
            ->orderBy('u.createdAt', 'DESC');

        if ($query) {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR u.email LIKE :q')
                ->setParameter('q', '%'.$query.'%');
        }
        if ($role) {
            $qb->andWhere('u.roles LIKE :role')->setParameter('role', '%"'.$role.'"%');
        }
        if ($status) {
            $qb->andWhere('u.status = :status')->setParameter('status', $status);
        }

        $total = (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $users = $qb->setFirstResult((self::PER_PAGE) * ($page - 1))
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()->getResult();

        return $this->render('admin/users.html.twig', [
            'adminNav' => 'users',
            'users' => $users,
            'query' => $query,
            'role' => $role,
            'status' => $status,
            'page' => $page,
            'pageCount' => (int) ceil($total / self::PER_PAGE),
            'total' => (int) $total,
        ]);
    }

    #[Route('/admin/utilisateurs/{id}', name: 'admin_user_show')]
    public function show(int $id, EntityManagerInterface $em, VerificationRequestRepository $verificationRequestRepository): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $sanctions = $em->getRepository(Sanction::class)->createQueryBuilder('s')
            ->join('s.admin', 'a')->addSelect('a')
            ->andWhere('s.user = :user')->setParameter('user', $user)
            ->orderBy('s.startAt', 'DESC')
            ->getQuery()->getResult();

        return $this->render('admin/user_show.html.twig', [
            'adminNav' => 'users',
            'user' => $user,
            'sanctions' => $sanctions,
            'verificationRequest' => $verificationRequestRepository->findLatestForTarget(ReportTargetType::PROFILE, $id),
        ]);
    }

    #[Route('/admin/utilisateurs/{id}/sanctionner', name: 'admin_users_sanction', methods: ['POST'])]
    public function sanction(int $id, Request $request, EntityManagerInterface $em, SanctionApplier $sanctionApplier): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('sanctionner-utilisateur-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $redirectRoute = 'admin_user_show' === $request->request->get('redirect_to') ? 'admin_user_show' : 'admin_users';
        $redirectParams = 'admin_user_show' === $redirectRoute ? ['id' => $id] : [];

        if ($this->getUser() === $user) {
            $this->addFlash('erreur', 'Vous ne pouvez pas sanctionner votre propre compte.');

            return $this->redirectToRoute($redirectRoute, $redirectParams);
        }

        $action = (string) $request->request->get('action');
        $reason = trim((string) $request->request->get('reason'));

        if ('reactiver' !== $action && !$reason) {
            $this->addFlash('erreur', 'Le motif est obligatoire.');

            return $this->redirectToRoute($redirectRoute, $redirectParams);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $sanctionApplier->apply($action, $user, $admin, $reason ?: 'Réactivation manuelle par un administrateur.');

        $this->addFlash('succes', 'Le compte a été mis à jour.');

        return $this->redirectToRoute($redirectRoute, $redirectParams);
    }

    /**
     * Attribution/retrait du rôle ROLE_ADMIN (cahier des charges — FONCTIONNALITÉ 9 §37) :
     * volontairement limité à ce seul rôle, purement additif. Retirer un
     * rôle métier (Talent/Étudiant/Enseignant/Recruteur) n'est pas proposé
     * ici : ajouter un rôle passe par un parcours dédié qui collecte les
     * informations nécessaires (inscription/rôles multiples §6/§12) — le
     * retirer impliquerait de décider du sort de données dédiées (profil
     * recruteur, rattachements d'établissement…), hors du cadre de cette
     * action. Action protégée (auto-modification interdite) et
     * systématiquement journalisée.
     */
    #[Route('/admin/utilisateurs/{id}/role', name: 'admin_users_toggle_admin_role', methods: ['POST'])]
    public function toggleAdminRole(int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('role-utilisateur-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        if ($this->getUser() === $user) {
            $this->addFlash('erreur', 'Vous ne pouvez pas modifier vos propres droits d\'administration.');

            return $this->redirectToRoute('admin_user_show', ['id' => $id]);
        }

        $roles = $user->getRoles();
        $isAdmin = \in_array('ROLE_ADMIN', $roles, true);
        $newRoles = array_values(array_diff($roles, ['ROLE_USER']));

        if ($isAdmin) {
            $newRoles = array_values(array_diff($newRoles, ['ROLE_ADMIN']));
        } else {
            $newRoles[] = 'ROLE_ADMIN';
        }
        $user->setRoles($newRoles);
        $em->flush();

        /** @var User $admin */
        $admin = $this->getUser();
        $auditLogger->log(
            $admin,
            AdminAuditAction::USER_ROLE_CHANGED,
            'User',
            $user->getId(),
            $user->getFullName(),
            $isAdmin ? 'Retrait du rôle administrateur.' : 'Attribution du rôle administrateur.',
        );

        $this->addFlash('succes', $isAdmin ? 'Les droits d\'administration ont été retirés.' : 'Les droits d\'administration ont été accordés.');

        return $this->redirectToRoute('admin_user_show', ['id' => $id]);
    }

    /**
     * Suppression définitive d'un compte (règle 9/10) : action réservée à un
     * administrateur, protégée par CSRF, confirmation textuelle explicite
     * ("SUPPRIMER") et interdiction de s'auto-supprimer (§28, même principe
     * que sanction()/toggleAdminRole() ci-dessus). Anonymise irréversiblement
     * le compte plutôt qu'une suppression physique — voir
     * {@see AccountDeletionService} pour l'analyse relation par relation.
     */
    #[Route('/admin/utilisateurs/{id}/supprimer-definitivement', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em, AccountDeletionService $deletionService, AdminAuditLogger $auditLogger): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('supprimer-definitivement-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        if ($this->getUser() === $user) {
            $this->addFlash('erreur', 'Vous ne pouvez pas supprimer votre propre compte depuis cette interface.');

            return $this->redirectToRoute('admin_user_show', ['id' => $id]);
        }

        if ('SUPPRIMER' !== trim((string) $request->request->get('confirmation'))) {
            $this->addFlash('erreur', 'Vous devez taper SUPPRIMER pour confirmer cette action irréversible.');

            return $this->redirectToRoute('admin_user_show', ['id' => $id]);
        }

        if (UserStatus::SUPPRIME === $user->getStatus()) {
            $this->addFlash('erreur', 'Ce compte est déjà supprimé.');

            return $this->redirectToRoute('admin_user_show', ['id' => $id]);
        }

        $fullName = $user->getFullName();
        $reason = trim((string) $request->request->get('reason'));

        $deletionService->delete($user);

        /** @var User $admin */
        $admin = $this->getUser();
        $auditLogger->log(
            $admin,
            AdminAuditAction::USER_DELETED,
            'User',
            $user->getId(),
            $fullName,
            $reason ?: 'Suppression définitive du compte.',
        );

        $this->addFlash('succes', 'Le compte a été supprimé définitivement.');

        return $this->redirectToRoute('admin_users');
    }
}
