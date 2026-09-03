<?php

namespace App\Entity;

use App\Repository\MentionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MentionRepository::class)]
#[ORM\Table(name: 'mention')]
#[ORM\UniqueConstraint(name: 'mention_domain_name_unique', columns: ['domain_id', 'name'])]
class Mention
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'mentions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Domain $domain = null;

    /** @var Collection<int, Specialty> */
    #[ORM\OneToMany(targetEntity: Specialty::class, mappedBy: 'mention', orphanRemoval: true)]
    private Collection $specialties;

    public function __construct()
    {
        $this->specialties = new ArrayCollection();
    }

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

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(?Domain $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    /** @return Collection<int, Specialty> */
    public function getSpecialties(): Collection
    {
        return $this->specialties;
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
