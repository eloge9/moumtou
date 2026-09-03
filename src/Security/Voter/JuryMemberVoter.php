<?php

namespace App\Security\Voter;

use App\Entity\JuryMember;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seul l'enseignant réellement invité (JuryMember::$invitedUser) peut
 * confirmer/refuser sa participation ou valider la tenue de la soutenance
 * depuis son espace connecté (cahier des charges §16, §20). La
 * confirmation par lien signé, elle, ne passe pas par ce Voter puisqu'elle
 * ne suppose pas de compte.
 */
class JuryMemberVoter extends Voter
{
    public const RESPOND = 'JURY_MEMBER_RESPOND';

    /**
     * Certifier que la soutenance a réellement eu lieu — n'est accordé
     * qu'à un membre ayant préalablement accepté son invitation (on ne
     * peut pas attester d'un événement auquel on n'a jamais confirmé
     * participer).
     */
    public const VALIDATE = 'JURY_MEMBER_VALIDATE_DEFENSE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::RESPOND, self::VALIDATE], true) && $subject instanceof JuryMember;
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

        if ($juryMember->getInvitedUser() !== $user) {
            return false;
        }

        if (self::VALIDATE === $attribute) {
            return \App\Enum\JuryStatus::CONFIRME === $juryMember->getStatus();
        }

        return true;
    }
}
