<?php

namespace App\Entity;

use App\Repository\RecruiterFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Talent enregistré par un recruteur (cahier des charges — FONCTIONNALITÉ 7
 * §17/§18) : contrainte unique (recruiter, talent), un même talent ne peut
 * pas être ajouté deux fois par le même recruteur.
 */
#[ORM\Entity(repositoryClass: RecruiterFavoriteRepository::class)]
#[ORM\Table(name: 'recruiter_favorite')]
#[ORM\UniqueConstraint(name: 'recruiter_favorite_unique', columns: ['recruiter_id', 'talent_id'])]
class RecruiterFavorite
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
