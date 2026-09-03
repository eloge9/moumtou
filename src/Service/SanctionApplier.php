<?php

namespace App\Service;

use App\Entity\Sanction;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\SanctionType;
use App\Enum\UserStatus;
use App\Security\SanctionMailer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique une sanction (avertissement, suspension, bannissement) et met à
 * jour le statut du compte en conséquence (cahier des charges §32/§33),
 * utilisé aussi bien depuis la modération d'un signalement que depuis la
 * gestion directe des utilisateurs par l'administrateur.
 */
class SanctionApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanctionMailer $mailer,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function apply(string $action, User $target, User $admin, string $reason): ?Sanction
    {
        $sanctionType = match ($action) {
            'avertir' => SanctionType::AVERTISSEMENT,
            'suspendre_7', 'suspendre_30' => SanctionType::SUSPENSION,
            'bannir' => SanctionType::BANNISSEMENT,
            'reactiver' => null,
            default => null,
        };

        if ('reactiver' === $action) {
            $target->setStatus(UserStatus::ACTIF);
            $this->em->flush();

            return null;
        }

        if (!$sanctionType) {
            return null;
        }

        $sanction = new Sanction();
        $sanction->setUser($target);
        $sanction->setAdmin($admin);
        $sanction->setType($sanctionType);
        $sanction->setReason($reason);
        if ('suspendre_7' === $action) {
            $sanction->setEndAt(new \DateTimeImmutable('+7 days'));
        } elseif ('suspendre_30' === $action) {
            $sanction->setEndAt(new \DateTimeImmutable('+30 days'));
        }
        $this->em->persist($sanction);

        if (SanctionType::SUSPENSION === $sanctionType) {
            $target->setStatus(UserStatus::SUSPENDU);
        } elseif (SanctionType::BANNISSEMENT === $sanctionType) {
            $target->setStatus(UserStatus::BANNI);
        }

        $this->em->flush();

        $this->mailer->notify($sanction);
        // sendEmail: false — SanctionMailer vient d'envoyer l'e-mail détaillé
        // (motif, durée…) ci-dessus ; la sécurité reste toujours notifiée en
        // interne (catégorie obligatoire, cahier §24), sans e-mail en double.
        $notificationType = match ($sanctionType) {
            SanctionType::AVERTISSEMENT => NotificationType::ACCOUNT_WARNED,
            SanctionType::SUSPENSION => NotificationType::ACCOUNT_SUSPENDED,
            SanctionType::BANNISSEMENT => NotificationType::ACCOUNT_BANNED,
        };
        $notificationMessage = \sprintf('Motif : %s', $reason);
        if ($sanction->getEndAt()) {
            $notificationMessage .= \sprintf(' Suspension jusqu\'au %s.', $sanction->getEndAt()->format('d/m/Y'));
        }
        $this->notificationService->notify(
            $target,
            $notificationType,
            $notificationType->label(),
            $notificationMessage,
            null,
            sendEmail: false,
        );

        return $sanction;
    }
}
