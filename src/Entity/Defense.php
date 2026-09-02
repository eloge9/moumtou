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

    /** @var Collection<int, JuryMember> */
    #[ORM\OneToMany(targetEntity: JuryMember::class, mappedBy: 'defense', orphanRemoval: true, cascade: ['persist'])]
    private Collection $juryMembers;

    public function __construct()
    {
        $this->announcedAt = new \DateTimeImmutable();
        $this->juryMembers = new ArrayCollection();
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

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getTime(): ?string
    {
        return $this->time;
    }

    public function setTime(string $time): static
    {
        $this->time = $time;

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(string $place): static
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

    public function getConfirmedCount(): int
    {
        return $this->juryMembers->filter(
            fn (JuryMember $member) => $member->getStatus() === \App\Enum\JuryStatus::CONFIRME
        )->count();
    }
}
