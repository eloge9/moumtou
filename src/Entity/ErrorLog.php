<?php

namespace App\Entity;

use App\Repository\ErrorLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal technique des erreurs serveur réelles (cahier des charges —
 * FONCTIONNALITÉ 18 §9/§25/§29) : distinct de {@see AdminAuditLog}, qui
 * trace QUI a fait QUOI (actions administratives volontaires) — ceci trace
 * des dysfonctionnements techniques (exceptions non prévues, 5xx), pour le
 * diagnostic, pas pour la responsabilité d'un utilisateur.
 *
 * Volume naturellement faible : uniquement les erreurs serveur réelles
 * (5xx), jamais les 403/404 qui font partie du fonctionnement normal.
 * Ne contient jamais de secret : seuls le message d'exception et son type
 * sont conservés, jamais la trace complète (celle-ci reste dans les
 * journaux techniques Monolog, eux-mêmes hors de portée publique).
 */
#[ORM\Entity(repositoryClass: ErrorLogRepository::class)]
#[ORM\Table(name: 'error_log')]
#[ORM\Index(columns: ['created_at'], name: 'error_log_created_at_idx')]
#[ORM\Index(columns: ['status_code'], name: 'error_log_status_code_idx')]
#[ORM\Index(columns: ['path'], name: 'error_log_path_idx')]
class ErrorLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private ?string $requestId = null;

    #[ORM\Column(length: 20)]
    private ?string $level = null;

    #[ORM\Column]
    private ?int $statusCode = null;

    #[ORM\Column(length: 10)]
    private ?string $method = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column(length: 255)]
    private ?string $exceptionClass = null;

    /** Message de l'exception uniquement — jamais la trace complète. */
    #[ORM\Column(type: 'text')]
    private ?string $message = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

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

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): static
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function getLevel(): ?string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = mb_substr($path, 0, 255);

        return $this;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function setExceptionClass(string $exceptionClass): static
    {
        $this->exceptionClass = $exceptionClass;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
