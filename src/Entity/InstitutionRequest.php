<?php

namespace App\Entity;

use App\Enum\InstitutionRequestStatus;
use App\Enum\InstitutionType;
use App\Repository\InstitutionRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'ajout d'un établissement absent du catalogue (gestion des
 * établissements — §4) : n'active jamais un établissement automatiquement,
 * seule une décision explicite de l'administrateur peut le faire.
 */
#[ORM\Entity(repositoryClass: InstitutionRequestRepository::class)]
#[ORM\Table(name: 'institution_request')]
class InstitutionRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $requestedBy = null;

    #[ORM\Column(length: 180)]
    private ?string $name = null;

    #[ORM\Column(enumType: InstitutionType::class)]
    private InstitutionType $type = InstitutionType::AUTRE;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $additionalInfo = null;

    #[ORM\Column(enumType: InstitutionRequestStatus::class)]
    private InstitutionRequestStatus $status = InstitutionRequestStatus::EN_ATTENTE;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Institution $createdInstitution = null;

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

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): static
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): InstitutionType
    {
        return $this->type;
    }

    public function setType(InstitutionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getAdditionalInfo(): ?string
    {
        return $this->additionalInfo;
    }

    public function setAdditionalInfo(?string $additionalInfo): static
    {
        $this->additionalInfo = $additionalInfo;

        return $this;
    }

    public function getStatus(): InstitutionRequestStatus
    {
        return $this->status;
    }

    public function setStatus(InstitutionRequestStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): static
    {
        $this->adminNote = $adminNote;

        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;

        return $this;
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

    public function getCreatedInstitution(): ?Institution
    {
        return $this->createdInstitution;
    }

    public function setCreatedInstitution(?Institution $createdInstitution): static
    {
        $this->createdInstitution = $createdInstitution;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
