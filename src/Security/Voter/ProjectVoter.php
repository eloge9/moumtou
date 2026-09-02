<?php

namespace App\Security\Voter;

use App\Entity\Project;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autorisation au niveau de la ressource "Project" : un talent ne peut
 * modifier/supprimer que ses propres projets, quel que soit ce qui est
 * affiché côté Twig (cahier des charges §12 : la vérification doit être
 * serveur, pas seulement visuelle).
 */
class ProjectVoter extends Voter
{
    public const EDIT = 'PROJECT_EDIT';
    public const DELETE = 'PROJECT_DELETE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof Project;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        /** @var Project $project */
        $project = $subject;

        return $project->getOwner() === $user;
    }
}
