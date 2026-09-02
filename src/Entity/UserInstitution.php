<?php

namespace App\Entity;

use App\Enum\InstitutionContext;
use App\Repository\UserInstitutionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rattachement d'un utilisateur à un établissement (gestion des
 * établissements — §5) : contrairement à `User::$institution` (un seul
 * établissement "principal", conservé pour compatibilité), cette table
 * permet plusieurs rattachements simultanés, avec un contexte distinct
 * pour chacun (étudiant dans l'un, enseignant dans un autre, etc.).
 */
#[ORM\Entity(repositoryClass: UserInstitutionRepository::class)]
#[ORM\Table(name: 'user_institution')]
#[ORM\UniqueConstraint(name: 'user_institution_context_unique', columns: ['user_id', 'institution_id', 'context'])]
class UserInstitution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'institutionAttachments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Institution $institution = null;

    #[ORM\Column(enumType: InstitutionContext::class)]
    private ?InstitutionContext $context = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    public function setInstitution(?Institution $institution): static
    {
        $this->institution = $institution;

        return $this;
    }

    public function getContext(): ?InstitutionContext
    {
        return $this->context;
    }

    public function setContext(?InstitutionContext $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
