<?php

namespace App\Entity;

use App\Enum\DefenseDecision;
use App\Enum\DefenseResultStatus;
use App\Repository\DefenseResultRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Résultat académique d'une soutenance (cahier des charges §12-§17) :
 * note, appréciation et décision, avec sa propre validation — distincte de
 * la vérification de la soutenance elle-même ({@see Defense::$status}).
 * Un talent ne peut jamais s'auto-attribuer un résultat : cette entité
 * n'est modifiable que par un juré confirmé de la soutenance, et la
 * validation finale est réservée au président du jury ou à l'administrateur.
 */
#[ORM\Entity(repositoryClass: DefenseResultRepository::class)]
#[ORM\Table(name: 'defense_result')]
class DefenseResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Defense::class, inversedBy: 'academicResult')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Defense $defense = null;

    #[ORM\Column(enumType: DefenseResultStatus::class)]
    private DefenseResultStatus $status = DefenseResultStatus::EN_ATTENTE;

    /** Note sur 20 (cahier des charges §13 : 0 ≤ note ≤ 20, barème modifiable à terme). */
    #[ORM\Column(nullable: true)]
    private ?float $grade = null;

    #[ORM\Column]
    private float $gradeScale = 20;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $appreciation = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $appreciationAuthor = null;

    #[ORM\Column(enumType: DefenseDecision::class)]
    private DefenseDecision $decision = DefenseDecision::EN_ATTENTE;

    /** Confidentialité (cahier des charges §23) : masqué au public par défaut. */
    #[ORM\Column]
    private bool $resultVisible = false;

    #[ORM\Column]
    private bool $gradeVisible = false;

    #[ORM\Column]
    private bool $appreciationVisible = false;

    #[ORM\Column]
    private bool $validated = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $validatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDefense(): ?Defense
    {
        return $this->defense;
    }

    public function setDefense(?Defense $defense): static
    {
        $this->defense = $defense;

        return $this;
    }

    public function getStatus(): DefenseResultStatus
    {
        return $this->status;
    }

    public function setStatus(DefenseResultStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getGrade(): ?float
    {
        return $this->grade;
    }

    public function setGrade(?float $grade): static
    {
        $this->grade = $grade;

        return $this;
    }

    public function getGradeScale(): float
    {
        return $this->gradeScale;
    }

    public function setGradeScale(float $gradeScale): static
    {
        $this->gradeScale = $gradeScale;

        return $this;
    }

    public function getAppreciation(): ?string
    {
        return $this->appreciation;
    }

    public function setAppreciation(?string $appreciation): static
    {
        $this->appreciation = $appreciation;

        return $this;
    }

    public function getAppreciationAuthor(): ?User
    {
        return $this->appreciationAuthor;
    }

    public function setAppreciationAuthor(?User $appreciationAuthor): static
    {
        $this->appreciationAuthor = $appreciationAuthor;

        return $this;
    }

    public function getDecision(): DefenseDecision
    {
        return $this->decision;
    }

    public function setDecision(DefenseDecision $decision): static
    {
        $this->decision = $decision;

        return $this;
    }

    public function isResultVisible(): bool
    {
        return $this->resultVisible;
    }

    public function setResultVisible(bool $resultVisible): static
    {
        $this->resultVisible = $resultVisible;

        return $this;
    }

    public function isGradeVisible(): bool
    {
        return $this->gradeVisible;
    }

    public function setGradeVisible(bool $gradeVisible): static
    {
        $this->gradeVisible = $gradeVisible;

        return $this;
    }

    public function isAppreciationVisible(): bool
    {
        return $this->appreciationVisible;
    }

    public function setAppreciationVisible(bool $appreciationVisible): static
    {
        $this->appreciationVisible = $appreciationVisible;

        return $this;
    }

    public function isValidated(): bool
    {
        return $this->validated;
    }

    public function setValidated(bool $validated): static
    {
        $this->validated = $validated;

        return $this;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function setValidatedBy(?User $validatedBy): static
    {
        $this->validatedBy = $validatedBy;

        return $this;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeImmutable $validatedAt): static
    {
        $this->validatedAt = $validatedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
