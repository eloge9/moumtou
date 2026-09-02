<?php

namespace App\Security;

use App\Entity\Sanction;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Empêche réellement un compte suspendu ou banni de se connecter (cahier des
 * charges §32/§35 : "un utilisateur suspendu/banni ne doit plus pouvoir
 * utiliser les fonctionnalités interdites"). Une suspension expirée est
 * levée automatiquement à la première tentative de connexion.
 */
class UserChecker implements UserCheckerInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (UserStatus::SUSPENDU === $user->getStatus() && $this->suspensionHasExpired($user)) {
            $user->setStatus(UserStatus::ACTIF);
            $this->em->flush();
        }

        if (UserStatus::BANNI === $user->getStatus()) {
            throw new CustomUserMessageAccountStatusException('Ce compte a été banni de la plateforme.');
        }

        if (UserStatus::SUSPENDU === $user->getStatus()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est temporairement suspendu.');
        }

        if (UserStatus::SUPPRIME === $user->getStatus()) {
            throw new CustomUserMessageAccountStatusException('Ce compte a été supprimé.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }

    private function suspensionHasExpired(User $user): bool
    {
        $lastSanction = $this->em->getRepository(Sanction::class)->createQueryBuilder('s')
            ->andWhere('s.user = :user')->setParameter('user', $user)
            ->orderBy('s.startAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $lastSanction && $lastSanction->getEndAt() && $lastSanction->getEndAt() < new \DateTimeImmutable();
    }
}
