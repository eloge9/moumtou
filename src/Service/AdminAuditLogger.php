<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'écriture unique du journal d'administration (cahier des charges —
 * FONCTIONNALITÉ 9 §40/§42) : chaque action admin significative passe par
 * ici, jamais par une insertion directe dispersée dans les contrôleurs.
 */
class AdminAuditLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function log(
        User $admin,
        AdminAuditAction $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $targetLabel = null,
        ?string $details = null,
    ): AdminAuditLog {
        $log = new AdminAuditLog();
        $log->setAdmin($admin);
        $log->setAction($action);
        $log->setTargetType($targetType);
        $log->setTargetId($targetId);
        $log->setTargetLabel($targetLabel);
        $log->setDetails($details);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }
}
