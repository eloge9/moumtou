<?php

namespace App\Entity;

use App\Repository\DefenseValidationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Confirmation, par UN membre du jury, que la soutenance a réellement eu
 * lieu (cahier des charges §19/§20) — distincte de l'acceptation de
 * l'invitation ({@see JuryMember::$status}), qui a lieu AVANT la
 * soutenance. Une soutenance devient "vérifiée" lorsqu'au moins 2
 * validations distinctes existent (voir {@see \App\Service\DefenseValidator}).
 */
#[ORM\Entity(repositoryClass: DefenseValidationRepository::class)]
#[ORM\Table(name: 'defense_validation')]
#[ORM\UniqueConstraint(name: 'defense_validation_unique', columns: ['defense_id', 'jury_member_id'])]
class DefenseValidation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Defense::class, inversedBy: 'validations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Defense $defense = null;

    #[ORM\ManyToOne(targetEntity: JuryMember::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?JuryMember $juryMember = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column]
    private \DateTimeImmutable $validatedAt;

    public function __construct()
    {
        $this->validatedAt = new \DateTimeImmutable();
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

    public function getJuryMember(): ?JuryMember
    {
        return $this->juryMember;
    }

    public function setJuryMember(?JuryMember $juryMember): static
    {
        $this->juryMember = $juryMember;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getValidatedAt(): \DateTimeImmutable
    {
        return $this->validatedAt;
    }
}
