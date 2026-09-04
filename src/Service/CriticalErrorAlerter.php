<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Alerte les administrateurs en cas d'erreur critique (cahier des charges —
 * FONCTIONNALITÉ 18 §33/§34) : réutilise {@see NotificationService} existant
 * (même canal que les alertes de sauvegarde, FONCTIONNALITÉ 16), avec un
 * regroupement anti-tempête — une même erreur (même classe + même message +
 * même route) ne déclenche qu'une seule alerte par fenêtre de temps, quel
 * que soit le nombre réel d'occurrences pendant cette fenêtre.
 */
class CriticalErrorAlerter
{
    private const COOLDOWN_SECONDS = 300; // 5 minutes, cf. exemple du cahier §34

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
        private readonly CacheInterface $cache,
    ) {
    }

    public function alert(string $exceptionClass, string $message, string $path, string $requestId): void
    {
        $signature = 'error_alert.'.hash('sha256', $exceptionClass.'|'.$message.'|'.$path);

        $isFirstOccurrenceInWindow = false;
        // get() n'exécute le callback que si l'entrée est absente/expirée :
        // sur un cache HIT (déjà alerté récemment pour cette signature), le
        // callback n'est jamais appelé et $isFirstOccurrenceInWindow reste false.
        $this->cache->get($signature, function (ItemInterface $item) use (&$isFirstOccurrenceInWindow) {
            $item->expiresAfter(self::COOLDOWN_SECONDS);
            $isFirstOccurrenceInWindow = true;

            return true;
        });

        if (!$isFirstOccurrenceInWindow) {
            return;
        }

        $admins = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->getQuery()->getResult();

        $title = 'Erreur critique détectée';
        $body = sprintf(
            "Une erreur critique est survenue sur MOUMTOU.\n\nType : %s\nRoute : %s\nRéférence : %s\n\nD'autres occurrences de la même erreur dans les %d prochaines minutes ne redéclencheront pas d'alerte (regroupement anti-spam).",
            $exceptionClass,
            $path,
            $requestId,
            (int) (self::COOLDOWN_SECONDS / 60),
        );

        foreach ($admins as $admin) {
            $this->notificationService->notify($admin, NotificationType::CRITICAL_ERROR, $title, $body);
        }
    }
}
