<?php

namespace App\Security\Voter;

use App\Entity\JuryMember;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seul l'enseignant réellement invité (JuryMember::$invitedUser) peut
 * confirmer/refuser sa participation depuis son espace connecté (cahier des
 * charges §16). La confirmation par lien signé, elle, ne passe pas par ce
 * Voter puisqu'elle ne suppose pas de compte.
 */
class JuryMemberVoter extends Voter
{
    public const RESPOND = 'JURY_MEMBER_RESPOND';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::RESPOND === $attribute && $subject instanceof JuryMember;
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

        /** @var JuryMember $juryMember */
        $juryMember = $subject;

        return $juryMember->getInvitedUser() === $user;
    }
}
