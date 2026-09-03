<?php

namespace App\Service\Backup;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Alerte les administrateurs en cas d'échec de sauvegarde critique (cahier
 * des charges — FONCTIONNALITÉ 16 §15) : réutilise {@see NotificationService}
 * existant, ne crée pas de second canal d'e-mail.
 */
class BackupAdminAlerter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function alertOnFailure(BackupRecord $record): void
    {
        if ($record->success) {
            return;
        }

        $admins = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->getQuery()->getResult();

        $title = sprintf('Échec de sauvegarde %s (%s)', $record->type, $record->tier);
        $message = sprintf(
            "La sauvegarde %s « %s » (palier %s) a échoué le %s.\n\nMotif : %s\n\nAction recommandée : vérifier le stockage disponible et l'outil MySQL, puis relancer la sauvegarde manuellement.",
            $record->kind,
            $record->type,
            $record->tier,
            $record->startedAt->format('d/m/Y H:i'),
            $record->error ?? 'inconnu',
        );

        foreach ($admins as $admin) {
            $this->notificationService->notify($admin, NotificationType::BACKUP_FAILED, $title, $message);
        }
    }
}
