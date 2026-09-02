<?php

namespace App\Entity;

use App\Repository\DomainRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'domain')]
class Domain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private ?string $name = null;

    /** @var Collection<int, Mention> */
    #[ORM\OneToMany(targetEntity: Mention::class, mappedBy: 'domain', orphanRemoval: true)]
    private Collection $mentions;

    public function __construct()
    {
        $this->mentions = new ArrayCollection();
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

    /** @return Collection<int, Mention> */
    public function getMentions(): Collection
    {
        return $this->mentions;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
