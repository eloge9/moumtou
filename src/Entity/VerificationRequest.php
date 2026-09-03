<?php

namespace App\Entity;

use App\Enum\ReportTargetType;
use App\Enum\VerificationStatus;
use App\Repository\VerificationRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de vérification MOUMTOU (cahier des charges — FONCTIONNALITÉ 14
 * §5/§29) : couche indépendante du statut de modération du projet
 * ({@see \App\Enum\ProjectStatus}). Cible polymorphe (projet ou profil) sur
 * le même modèle que {@see Report}/{@see ModerationAction} déjà utilisé dans
 * le projet — pas de nouveau système concurrent.
 *
 * Une seule demande "ouverte" ({@see VerificationStatus::isOpen()}) peut
 * exister à la fois pour une cible donnée ; une fois REFUSEE/RETIREE, une
 * nouvelle demande (nouvelle ligne) peut être créée pour un nouveau cycle.
 */
#[ORM\Entity(repositoryClass: VerificationRequestRepository::class)]
#[ORM\Table(name: 'verification_request')]
#[ORM\Index(columns: ['status'], name: 'verification_request_status_idx')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'verification_request_target_idx')]
#[ORM\Index(columns: ['created_at'], name: 'verification_request_created_idx')]
class VerificationRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ReportTargetType::class)]
    private ?ReportTargetType $targetType = null;

    #[ORM\Column]
    private ?int $targetId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $requester = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $reviewer = null;

    #[ORM\Column(enumType: VerificationStatus::class)]
    private VerificationStatus $status = VerificationStatus::EN_ATTENTE;

    /** Dernier motif communiqué (correction/refus/retrait) — cahier §13. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** @var Collection<int, VerificationEvent> */
    #[ORM\OneToMany(targetEntity: VerificationEvent::class, mappedBy: 'request', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $events;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRequester(): ?User
    {
        return $this->requester;
    }

    public function setRequester(?User $requester): static
    {
        $this->requester = $requester;

        return $this;
    }

    public function getReviewer(): ?User
    {
        return $this->reviewer;
    }

    public function setReviewer(?User $reviewer): static
    {
        $this->reviewer = $reviewer;

        return $this;
    }

    public function getStatus(): VerificationStatus
    {
        return $this->status;
    }

    public function setStatus(VerificationStatus $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    /** @return Collection<int, VerificationEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(VerificationEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setRequest($this);
        }

        return $this;
    }
}
