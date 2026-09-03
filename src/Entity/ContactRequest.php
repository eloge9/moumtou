<?php

namespace App\Entity;

use App\Enum\ContactRequestStatus;
use App\Repository\ContactRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de mise en relation recruteur → talent (cahier des charges —
 * FONCTIONNALITÉ 7 §13/§14). Ne transmet jamais de coordonnées privées :
 * seul un message et une décision (accepter/refuser) transitent ici, le
 * contact réel se fait ensuite via les canaux déjà publics/autorisés du
 * talent (WhatsApp, LinkedIn…) — cf. §22.
 */
#[ORM\Entity(repositoryClass: ContactRequestRepository::class)]
#[ORM\Table(name: 'contact_request')]
#[ORM\Index(columns: ['talent_id', 'status'], name: 'contact_request_talent_status_idx')]
#[ORM\Index(columns: ['recruiter_id', 'status'], name: 'contact_request_recruiter_status_idx')]
class ContactRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $recruiter = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $talent = null;

    #[ORM\Column(type: 'text')]
    private ?string $message = null;

    #[ORM\Column(enumType: ContactRequestStatus::class)]
    private ContactRequestStatus $status = ContactRequestStatus::PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecruiter(): ?User
    {
        return $this->recruiter;
    }

    public function setRecruiter(?User $recruiter): static
    {
        $this->recruiter = $recruiter;

        return $this;
    }

    public function getTalent(): ?User
    {
        return $this->talent;
    }

    public function setTalent(?User $talent): static
    {
        $this->talent = $talent;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getStatus(): ContactRequestStatus
    {
        return $this->status;
    }

    public function setStatus(ContactRequestStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function setRespondedAt(?\DateTimeImmutable $respondedAt): static
    {
        $this->respondedAt = $respondedAt;

        return $this;
    }
}
