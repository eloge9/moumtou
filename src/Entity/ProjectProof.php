<?php

namespace App\Entity;

use App\Enum\ProofType;
use App\Repository\ProjectProofRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectProofRepository::class)]
#[ORM\Table(name: 'project_proof')]
class ProjectProof
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'proofs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    #[ORM\Column(enumType: ProofType::class)]
    private ?ProofType $type = null;

    /**
     * Titre éventuel (cahier des charges — FONCTIONNALITÉ 10 §6 : "chaque
     * lien doit posséder au minimum : type, titre éventuel, URL"). Utile
     * notamment pour distinguer plusieurs preuves de type AUTRE.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 500)]
    private ?string $url = null;

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

    public function getType(): ?ProofType
    {
        return $this->type;
    }

    public function setType(ProofType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }
}
