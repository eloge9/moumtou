<?php

namespace App\Entity;

use App\Repository\SpecialtyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpecialtyRepository::class)]
#[ORM\Table(name: 'specialty')]
#[ORM\UniqueConstraint(name: 'specialty_mention_name_unique', columns: ['mention_id', 'name'])]
class Specialty
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: Mention::class, inversedBy: 'specialties')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mention $mention = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getMention(): ?Mention
    {
        return $this->mention;
    }

    public function setMention(?Mention $mention): static
    {
        $this->mention = $mention;

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

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
