<?php

namespace App\Entity;

use App\Enum\VerificationStatus;
use App\Repository\VerificationEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique d'une {@see VerificationRequest} (cahier des charges —
 * FONCTIONNALITÉ 14 §14) : contrairement à {@see AdminAuditLog}, l'acteur
 * peut être le demandeur (talent) lui-même — "Demande créée",
 * "Nouvelle soumission" — pas seulement un administrateur.
 */
#[ORM\Entity(repositoryClass: VerificationEventRepository::class)]
#[ORM\Table(name: 'verification_event')]
#[ORM\Index(columns: ['request_id'], name: 'verification_event_request_idx')]
class VerificationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VerificationRequest::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false)]
    private ?VerificationRequest $request = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actor = null;

    #[ORM\Column(enumType: VerificationStatus::class, nullable: true)]
    private ?VerificationStatus $previousStatus = null;

    #[ORM\Column(enumType: VerificationStatus::class)]
    private ?VerificationStatus $newStatus = null;

    /** Motif/commentaire libre associé à cette transition, le cas échéant. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

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

    public function getRequest(): ?VerificationRequest
    {
        return $this->request;
    }

    public function setRequest(?VerificationRequest $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getPreviousStatus(): ?VerificationStatus
    {
        return $this->previousStatus;
    }

    public function setPreviousStatus(?VerificationStatus $previousStatus): static
    {
        $this->previousStatus = $previousStatus;

        return $this;
    }

    public function getNewStatus(): ?VerificationStatus
    {
        return $this->newStatus;
    }

    public function setNewStatus(VerificationStatus $newStatus): static
    {
        $this->newStatus = $newStatus;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
