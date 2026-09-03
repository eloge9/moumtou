<?php

namespace App\Entity;

use App\Repository\TalentViewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des consultations de profils talents par un recruteur (cahier des
 * charges — FONCTIONNALITÉ 7 §20) : simple historique append-only, pas de
 * contrainte d'unicité (chaque consultation est enregistrée), utilisé pour
 * le dashboard ("talents consultés") et l'historique paginé.
 */
#[ORM\Entity(repositoryClass: TalentViewRepository::class)]
#[ORM\Table(name: 'talent_view')]
#[ORM\Index(columns: ['recruiter_id', 'viewed_at'], name: 'talent_view_recruiter_idx')]
class TalentView
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
    private \DateTimeImmutable $viewedAt;

    public function __construct()
    {
        $this->viewedAt = new \DateTimeImmutable();
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

    public function getViewedAt(): \DateTimeImmutable
    {
        return $this->viewedAt;
    }
}
