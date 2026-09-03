<?php

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un utilisateur ne peut supprimer que son propre commentaire (cahier des
 * charges §23 : "supprimer leur commentaire").
 */
class CommentVoter extends Voter
{
    public const DELETE = 'COMMENT_DELETE';
    public const EDIT = 'COMMENT_EDIT';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::DELETE, self::EDIT], true) && $subject instanceof Comment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Comment $comment */
        $comment = $subject;

        if (self::EDIT === $attribute) {
            // Seul l'auteur peut réécrire son propre commentaire — la
            // modération (masquer/supprimer/restaurer) reste distincte
            // et réservée à l'administrateur (cahier des charges §13/§20).
            return $comment->getAuthor() === $user;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $comment->getAuthor() === $user;
    }
}
