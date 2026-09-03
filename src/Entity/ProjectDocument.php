<?php

namespace App\Entity;

use App\Enum\ProjectDocumentType;
use App\Repository\ProjectDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Document associé à un projet (cahier des charges — FONCTIONNALITÉ 10 §9) :
 * rapport, présentation, documentation, publication ou autre document
 * autorisé. Réutilise le même répertoire d'upload que {@see ProjectPhoto}
 * (public/uploads/projects/{id}), sous-dossier "documents".
 */
#[ORM\Entity(repositoryClass: ProjectDocumentRepository::class)]
#[ORM\Table(name: 'project_document')]
#[ORM\Index(columns: ['project_id'], name: 'project_document_project_idx')]
class ProjectDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    #[ORM\Column(enumType: ProjectDocumentType::class)]
    private ?ProjectDocumentType $type = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column(length: 180)]
    private ?string $originalFilename = null;

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
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

    public function getType(): ?ProjectDocumentType
    {
        return $this->type;
    }

    public function setType(ProjectDocumentType $type): static
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

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
