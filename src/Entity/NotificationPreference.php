<?php

namespace App\Entity;

use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Préférence de notification d'un utilisateur pour une catégorie donnée
 * (cahier des charges — FONCTIONNALITÉ 8 §24/§25). L'absence de ligne pour
 * un couple (user, catégorie) vaut valeurs par défaut — voir
 * {@see \App\Repository\NotificationPreferenceRepository::resolve()}.
 */
#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preference')]
#[ORM\UniqueConstraint(name: 'notification_preference_unique', columns: ['user_id', 'category'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(enumType: NotificationCategory::class)]
    private ?NotificationCategory $category = null;

    #[ORM\Column]
    private bool $inAppEnabled = true;

    #[ORM\Column]
    private bool $emailEnabled = true;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCategory(): ?NotificationCategory
    {
        return $this->category;
    }

    public function setCategory(NotificationCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function isInAppEnabled(): bool
    {
        return $this->inAppEnabled;
    }

    public function setInAppEnabled(bool $inAppEnabled): static
    {
        $this->inAppEnabled = $inAppEnabled;

        return $this;
    }

    public function isEmailEnabled(): bool
    {
        return $this->emailEnabled;
    }

    public function setEmailEnabled(bool $emailEnabled): static
    {
        $this->emailEnabled = $emailEnabled;

        return $this;
    }
}
