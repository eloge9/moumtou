<?php

namespace App\Entity;

use App\Enum\AnalyticsEventType;
use App\Repository\AnalyticsEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'événements analytics (cahier des charges — FONCTIONNALITÉ 12
 * §28/§29) : append-only, à l'image de {@see TalentView}. Point unique de
 * mesure pour tout ce qui n'a pas déjà de traçabilité propre.
 *
 * Confidentialité (§2/§5) : jamais d'adresse IP, jamais de contenu de
 * message ; un visiteur anonyme n'est identifié que par un hachage
 * (SHA-256) non réversible de son identifiant de session — jamais la
 * session elle-même. `user` n'est renseigné que pour un visiteur
 * authentifié, jamais reconstruit a posteriori.
 */
#[ORM\Entity(repositoryClass: AnalyticsEventRepository::class)]
#[ORM\Table(name: 'analytics_event')]
#[ORM\Index(columns: ['project_id', 'type', 'created_at'], name: 'analytics_event_project_type_idx')]
#[ORM\Index(columns: ['type', 'created_at'], name: 'analytics_event_type_created_idx')]
#[ORM\Index(columns: ['project_id', 'type', 'visitor_hash', 'created_at'], name: 'analytics_event_dedup_idx')]
class AnalyticsEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: AnalyticsEventType::class)]
    private ?AnalyticsEventType $type = null;

    /**
     * Nullable : certains événements (ex. recherche par technologie) ne
     * portent sur aucun projet précis (cahier — FONCTIONNALITÉ 12 §19).
     */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    /**
     * Hachage SHA-256 de l'identifiant de session du visiteur (jamais la
     * session en clair, jamais une IP) — sert uniquement à distinguer des
     * visiteurs anonymes entre eux pour le calcul des vues uniques et la
     * déduplication anti-abus (cahier §5/§6).
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $visitorHash = null;

    /**
     * Donnée contextuelle minimale selon le type : 'direct'|'qr' pour une
     * vue, un {@see \App\Enum\ProofType} pour un clic de preuve, 'svg'|'png'
     * pour un téléchargement de QR code.
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $metadata = null;

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

    public function getType(): ?AnalyticsEventType
    {
        return $this->type;
    }

    public function setType(AnalyticsEventType $type): static
    {
        $this->type = $type;

        return $this;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getVisitorHash(): ?string
    {
        return $this->visitorHash;
    }

    public function setVisitorHash(?string $visitorHash): static
    {
        $this->visitorHash = $visitorHash;

        return $this;
    }

    public function getMetadata(): ?string
    {
        return $this->metadata;
    }

    public function setMetadata(?string $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
