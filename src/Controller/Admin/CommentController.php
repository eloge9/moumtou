<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Enum\AdminAuditAction;
use App\Enum\CommentStatus;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modération des commentaires (cahier des charges — FONCTIONNALITÉ 9 §14/§45) :
 * section admin dédiée, indépendante du circuit de signalement — un
 * administrateur doit pouvoir retrouver et modérer n'importe quel
 * commentaire sans attendre qu'il soit signalé.
 */
#[IsGranted('ROLE_ADMIN')]
class CommentController extends AbstractController
{
    private const PER_PAGE = 30;

    #[Route('/admin/commentaires', name: 'admin_comments')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $status = CommentStatus::tryFrom((string) $request->query->get('status', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $qb = $em->getRepository(Comment::class)->createQueryBuilder('c')
            ->join('c.author', 'a')->addSelect('a')
            ->join('c.project', 'p')->addSelect('p')
            ->orderBy('c.createdAt', 'DESC');

        if ($query) {
            $qb->andWhere('c.content LIKE :q OR a.firstName LIKE :q OR a.lastName LIKE :q OR p.name LIKE :q')
                ->setParameter('q', '%'.$query.'%');
        }
        if ($status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        $comments = $qb->setFirstResult(self::PER_PAGE * ($page - 1))
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()->getResult();

        return $this->render('admin/comments.html.twig', [
            'adminNav' => 'comments',
            'comments' => $comments,
            'query' => $query,
            'status' => $status,
            'page' => $page,
            'pageCount' => (int) ceil($total / self::PER_PAGE),
            'total' => $total,
        ]);
    }

    #[Route('/admin/commentaires/{id}/action', name: 'admin_comments_action', methods: ['POST'])]
    public function action(int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $comment = $em->getRepository(Comment::class)->find($id);
        if (!$comment) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('moderation-commentaire-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $action = (string) $request->request->get('action');
        $reason = trim((string) $request->request->get('reason'));

        $newStatus = match ($action) {
            'masquer' => CommentStatus::MASQUE,
            'supprimer' => CommentStatus::SUPPRIME,
            'restaurer' => CommentStatus::VISIBLE,
            default => null,
        };

        if (!$newStatus) {
            throw $this->createNotFoundException();
        }

        if ('restaurer' !== $action && !$reason) {
            $this->addFlash('erreur', 'Le motif est obligatoire.');

            return $this->redirectToRoute('admin_comments');
        }

        $comment->setStatus($newStatus);
        $em->flush();

        /** @var \App\Entity\User $admin */
        $admin = $this->getUser();
        $auditAction = match ($action) {
            'masquer' => AdminAuditAction::COMMENT_HIDDEN,
            'supprimer' => AdminAuditAction::COMMENT_DELETED,
            'restaurer' => AdminAuditAction::COMMENT_RESTORED,
        };
        $auditLogger->log($admin, $auditAction, 'Comment', $comment->getId(), null, $reason ?: 'Restauration manuelle par un administrateur.');

        $this->addFlash('succes', 'Le commentaire a été mis à jour.');

        return $this->redirectToRoute('admin_comments');
    }
}
