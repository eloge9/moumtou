<?php

namespace App\Entity;

use App\Enum\AdminAuditAction;
use App\Repository\AdminAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'administration (cahier des charges — FONCTIONNALITÉ 9 §40/§42) :
 * trace immuable de chaque action administrative significative. Distinct de
 * {@see ModerationAction} (historique détaillé propre à un signalement
 * traité) — celui-ci couvre l'ensemble du back-office (utilisateurs,
 * catalogue, établissements…), pas seulement les décisions de modération.
 * Aucune route d'édition n'existe pour cette entité : elle n'est écrite que
 * par {@see \App\Service\AdminAuditLogger}, jamais par un formulaire admin.
 */
#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Table(name: 'admin_audit_log')]
#[ORM\Index(columns: ['admin_id'], name: 'admin_audit_log_admin_idx')]
#[ORM\Index(columns: ['action'], name: 'admin_audit_log_action_idx')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'admin_audit_log_target_idx')]
#[ORM\Index(columns: ['created_at'], name: 'admin_audit_log_created_idx')]
class AdminAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $admin = null;

    #[ORM\Column(enumType: AdminAuditAction::class)]
    private ?AdminAuditAction $action = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $targetType = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $targetLabel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdmin(): ?User
    {
        return $this->admin;
    }

    public function setAdmin(?User $admin): static
    {
        $this->admin = $admin;

        return $this;
    }

    public function getAction(): ?AdminAuditAction
    {
        return $this->action;
    }

    public function setAction(AdminAuditAction $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function setTargetType(?string $targetType): static
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function setTargetId(?int $targetId): static
    {
        $this->targetId = $targetId;

        return $this;
    }

    public function getTargetLabel(): ?string
    {
        return $this->targetLabel;
    }

    public function setTargetLabel(?string $targetLabel): static
    {
        $this->targetLabel = $targetLabel;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
