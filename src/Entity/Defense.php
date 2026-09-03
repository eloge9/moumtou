<?php

namespace App\Entity;

use App\Enum\DefenseStatus;
use App\Repository\DefenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DefenseRepository::class)]
#[ORM\Table(name: 'defense')]
class Defense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Project::class, inversedBy: 'defense')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Project $project = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 10)]
    private ?string $time = null;

    #[ORM\Column(length: 180)]
    private ?string $place = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(enumType: DefenseStatus::class)]
    private DefenseStatus $status = DefenseStatus::ANNONCEE;

    #[ORM\Column]
    private \DateTimeImmutable $announcedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cancellationReason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $postponementReason = null;

    /** Ancienne date conservée à titre d'historique lors d'un report. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $previousDate = null;

    /**
     * Empêche l'envoi de plusieurs rappels par e-mail pour la même
     * soutenance (cahier des charges §28/§29).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reminderSentAt = null;

    /** Date à laquelle le seuil de validations du jury a été atteint (cahier — FONCTIONNALITÉ 14 §18). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /** @var Collection<int, JuryMember> */
    #[ORM\OneToMany(targetEntity: JuryMember::class, mappedBy: 'defense', orphanRemoval: true, cascade: ['persist'])]
    private Collection $juryMembers;

    /**
     * Confirmations post-soutenance ("elle a réellement eu lieu"),
     * distinctes de l'acceptation d'invitation portée par {@see JuryMember}.
     *
     * @var Collection<int, DefenseValidation>
     */
    #[ORM\OneToMany(targetEntity: DefenseValidation::class, mappedBy: 'defense', orphanRemoval: true)]
    private Collection $validations;

    /**
     * Résultat académique structuré (note/appréciation/décision) — cahier
     * §12-§17. Distinct de {@see $result}, mention libre déjà existante.
     */
    #[ORM\OneToOne(targetEntity: DefenseResult::class, mappedBy: 'defense', cascade: ['persist', 'remove'])]
    private ?DefenseResult $academicResult = null;

    public function __construct()
    {
        $this->announcedAt = new \DateTimeImmutable();
        $this->juryMembers = new ArrayCollection();
        $this->validations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getTime(): ?string
    {
        return $this->time;
    }

    public function setTime(?string $time): static
    {
        $this->time = $time;

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $place): static
    {
        $this->place = $place;

        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function getStatus(): DefenseStatus
    {
        return $this->status;
    }

    public function setStatus(DefenseStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAnnouncedAt(): \DateTimeImmutable
    {
        return $this->announcedAt;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): static
    {
        $this->cancellationReason = $cancellationReason;

        return $this;
    }

    public function getPostponementReason(): ?string
    {
        return $this->postponementReason;
    }

    public function setPostponementReason(?string $postponementReason): static
    {
        $this->postponementReason = $postponementReason;

        return $this;
    }

    public function getPreviousDate(): ?\DateTimeImmutable
    {
        return $this->previousDate;
    }

    public function setPreviousDate(?\DateTimeImmutable $previousDate): static
    {
        $this->previousDate = $previousDate;

        return $this;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function setReminderSentAt(?\DateTimeImmutable $reminderSentAt): static
    {
        $this->reminderSentAt = $reminderSentAt;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    /** @return Collection<int, JuryMember> */
    public function getJuryMembers(): Collection
    {
        return $this->juryMembers;
    }

    public function addJuryMember(JuryMember $juryMember): static
    {
        if (!$this->juryMembers->contains($juryMember)) {
            $this->juryMembers->add($juryMember);
            $juryMember->setDefense($this);
        }

        return $this;
    }

    public function removeJuryMember(JuryMember $juryMember): static
    {
        $this->juryMembers->removeElement($juryMember);

        return $this;
    }

    /** Nombre de membres ayant accepté l'invitation (avant la soutenance). */
    public function getConfirmedCount(): int
    {
        return $this->juryMembers->filter(
            fn (JuryMember $member) => $member->getStatus() === \App\Enum\JuryStatus::CONFIRME
        )->count();
    }

    /** @return Collection<int, DefenseValidation> */
    public function getValidations(): Collection
    {
        return $this->validations;
    }

    /** Nombre de membres ayant certifié que la soutenance a réellement eu lieu. */
    public function getValidationCount(): int
    {
        return $this->validations->count();
    }

    public function getAcademicResult(): ?DefenseResult
    {
        return $this->academicResult;
    }

    public function setAcademicResult(?DefenseResult $academicResult): static
    {
        $this->academicResult = $academicResult;
        if ($academicResult && $academicResult->getDefense() !== $this) {
            $academicResult->setDefense($this);
        }

        return $this;
    }
}
