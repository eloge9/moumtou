<?php

namespace App\Entity;

use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Repository\ReportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signalement à cible polymorphe (targetType + targetId) : Project, User (profil)
 * ou Comment. Pas de contrainte de clé étrangère directe, résolue au niveau service.
 */
#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Table(name: 'report')]
// Cahier des charges — FONCTIONNALITÉ 17 §5/§16 : `status` est filtré à
// chaque ouverture du tableau de bord de modération (3 comptages) ;
// (target_type, target_id) est utilisé pour retrouver les signalements
// existants sur une même cible (doublons, historique).
#[ORM\Index(columns: ['status'], name: 'report_status_idx')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'report_target_idx')]
class Report
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $reporter = null;

    #[ORM\Column(enumType: ReportTargetType::class)]
    private ?ReportTargetType $targetType = null;

    #[ORM\Column]
    private ?int $targetId = null;

    #[ORM\Column(enumType: ReportReason::class)]
    private ?ReportReason $reason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(enumType: ReportStatus::class)]
    private ReportStatus $status = ReportStatus::OUVERT;

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

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function setReporter(?User $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function getTargetType(): ?ReportTargetType
    {
        return $this->targetType;
    }

    public function setTargetType(ReportTargetType $targetType): static
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function setTargetId(int $targetId): static
    {
        $this->targetId = $targetId;

        return $this;
    }

    public function getReason(): ?ReportReason
    {
        return $this->reason;
    }

    public function setReason(ReportReason $reason): static
    {
        $this->reason = $reason;

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

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function setStatus(ReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
