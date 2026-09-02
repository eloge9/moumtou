<?php

namespace App\Entity;

use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $name = null;

    #[ORM\Column(length: 160, nullable: true, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $theme = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $detailedDescription = null;

    #[ORM\Column(enumType: ProjectType::class)]
    private ?ProjectType $type = null;

    #[ORM\Column(enumType: ProjectStatus::class)]
    private ProjectStatus $status = ProjectStatus::BROUILLON;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $realizationDate = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Mention::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Mention $mention = null;

    #[ORM\ManyToOne(targetEntity: Specialty::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Specialty $specialty = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Institution $institution = null;

    #[ORM\Column]
    private int $viewsCount = 0;

    #[ORM\Column]
    private float $ratingAverage = 0;

    #[ORM\Column]
    private int $ratingsCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, Technology> */
    #[ORM\ManyToMany(targetEntity: Technology::class)]
    #[ORM\JoinTable(name: 'project_technology')]
    private Collection $technologies;

    /** @var Collection<int, ProjectProof> */
    #[ORM\OneToMany(targetEntity: ProjectProof::class, mappedBy: 'project', orphanRemoval: true, cascade: ['persist'])]
    private Collection $proofs;

    /** @var Collection<int, ProjectPhoto> */
    #[ORM\OneToMany(targetEntity: ProjectPhoto::class, mappedBy: 'project', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

    #[ORM\OneToOne(targetEntity: Defense::class, mappedBy: 'project', cascade: ['persist', 'remove'])]
    private ?Defense $defense = null;

    /** @var Collection<int, Rating> */
    #[ORM\OneToMany(targetEntity: Rating::class, mappedBy: 'project', orphanRemoval: true)]
    private Collection $ratings;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'project', orphanRemoval: true)]
    private Collection $comments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->technologies = new ArrayCollection();
        $this->proofs = new ArrayCollection();
        $this->photos = new ArrayCollection();
        $this->ratings = new ArrayCollection();
        $this->comments = new ArrayCollection();
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function setTheme(?string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;

        return $this;
    }

    public function getDetailedDescription(): ?string
    {
        return $this->detailedDescription;
    }

    public function setDetailedDescription(?string $detailedDescription): static
    {
        $this->detailedDescription = $detailedDescription;

        return $this;
    }

    public function getType(): ?ProjectType
    {
        return $this->type;
    }

    public function setType(ProjectType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): ProjectStatus
    {
        return $this->status;
    }

    public function setStatus(ProjectStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->status === ProjectStatus::VERIFIE;
    }

    public function getRealizationDate(): ?\DateTimeImmutable
    {
        return $this->realizationDate;
    }

    public function setRealizationDate(?\DateTimeImmutable $realizationDate): static
    {
        $this->realizationDate = $realizationDate;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

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

    public function getMention(): ?Mention
    {
        return $this->mention;
    }

    public function setMention(?Mention $mention): static
    {
        $this->mention = $mention;

        return $this;
    }

    public function getSpecialty(): ?Specialty
    {
        return $this->specialty;
    }

    public function setSpecialty(?Specialty $specialty): static
    {
        $this->specialty = $specialty;

        return $this;
    }

    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    public function setInstitution(?Institution $institution): static
    {
        $this->institution = $institution;

        return $this;
    }

    public function getViewsCount(): int
    {
        return $this->viewsCount;
    }

    public function incrementViewsCount(): static
    {
        ++$this->viewsCount;

        return $this;
    }

    public function getRatingAverage(): float
    {
        return $this->ratingAverage;
    }

    public function setRatingAverage(float $ratingAverage): static
    {
        $this->ratingAverage = $ratingAverage;

        return $this;
    }

    public function getRatingsCount(): int
    {
        return $this->ratingsCount;
    }

    public function setRatingsCount(int $ratingsCount): static
    {
        $this->ratingsCount = $ratingsCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    /** @return Collection<int, Technology> */
    public function getTechnologies(): Collection
    {
        return $this->technologies;
    }

    public function addTechnology(Technology $technology): static
    {
        if (!$this->technologies->contains($technology)) {
            $this->technologies->add($technology);
        }

        return $this;
    }

    public function removeTechnology(Technology $technology): static
    {
        $this->technologies->removeElement($technology);

        return $this;
    }

    /** @return Collection<int, ProjectProof> */
    public function getProofs(): Collection
    {
        return $this->proofs;
    }

    public function addProof(ProjectProof $proof): static
    {
        if (!$this->proofs->contains($proof)) {
            $this->proofs->add($proof);
            $proof->setProject($this);
        }

        return $this;
    }

    public function removeProof(ProjectProof $proof): static
    {
        $this->proofs->removeElement($proof);

        return $this;
    }

    /** @return Collection<int, ProjectPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(ProjectPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setProject($this);
        }

        return $this;
    }

    public function removePhoto(ProjectPhoto $photo): static
    {
        $this->photos->removeElement($photo);

        return $this;
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

    /** @return Collection<int, Rating> */
    public function getRatings(): Collection
    {
        return $this->ratings;
    }

    /** @return Collection<int, Comment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
