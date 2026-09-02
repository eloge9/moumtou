<?php

namespace App\Entity;

use App\Enum\Availability;
use App\Enum\InstitutionContext;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'user_email_unique', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'user_slug_unique', columns: ['slug'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire.')]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.')]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 30)]
    private ?string $phone = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $whatsapp = null;

    #[ORM\Column]
    private bool $whatsappEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $linkedinUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $githubUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $portfolioUrl = null;

    #[ORM\Column(enumType: Availability::class, nullable: true)]
    private ?Availability $availability = null;

    /**
     * Établissement de rattachement (cahier des charges §4.4, "renseigner
     * son institution" — principalement utilisé par les enseignants, mais
     * ouvert à tout profil).
     */
    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Institution $institution = null;

    /**
     * Informations académiques du profil (cahier des charges — gestion des
     * établissements §7), distinctes de la classification par projet déjà
     * existante sur {@see Project} : un profil peut afficher un cursus même
     * sans avoir encore publié de projet de soutenance.
     */
    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Mention::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Mention $mention = null;

    #[ORM\ManyToOne(targetEntity: Specialty::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Specialty $specialty = null;

    /**
     * Rattachements multiples à des établissements (étudiant ici,
     * enseignant ailleurs...) — {@see $institution} ci-dessus reste
     * l'établissement "principal" affiché par défaut, pour compatibilité
     * avec l'existant ; cette collection est la source de vérité pour le
     * multi-rattachement (gestion des établissements §5).
     *
     * @var Collection<int, UserInstitution>
     */
    #[ORM\OneToMany(targetEntity: UserInstitution::class, mappedBy: 'user', orphanRemoval: true, cascade: ['persist'])]
    private Collection $institutionAttachments;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $facebookId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $linkedinId = null;

    #[ORM\Column]
    private bool $emailVerified = false;

    #[ORM\Column(enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::ACTIF;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Skill> */
    #[ORM\ManyToMany(targetEntity: Skill::class)]
    #[ORM\JoinTable(name: 'user_skill')]
    private Collection $skills;

    /** @var Collection<int, Technology> */
    #[ORM\ManyToMany(targetEntity: Technology::class)]
    #[ORM\JoinTable(name: 'user_technology')]
    private Collection $technologies;

    /** @var Collection<int, Project> */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'owner')]
    private Collection $projects;

    /** @var Collection<int, Experience> */
    #[ORM\OneToMany(targetEntity: Experience::class, mappedBy: 'user', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['startDate' => 'DESC'])]
    private Collection $experiences;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->skills = new ArrayCollection();
        $this->technologies = new ArrayCollection();
        $this->projects = new ArrayCollection();
        $this->experiences = new ArrayCollection();
        $this->institutionAttachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // garantit que chaque utilisateur authentifié a au moins ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Efface les informations sensibles et temporaires stockées sur l'utilisateur.
     */
    public function eraseCredentials(): void
    {
        // Si des données sensibles temporaires sont stockées sur l'utilisateur, les effacer ici.
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return trim(sprintf('%s %s', $this->firstName, $this->lastName));
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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getWhatsapp(): ?string
    {
        return $this->whatsapp;
    }

    public function setWhatsapp(?string $whatsapp): static
    {
        $this->whatsapp = $whatsapp;

        return $this;
    }

    public function isWhatsappEnabled(): bool
    {
        return $this->whatsappEnabled;
    }

    public function setWhatsappEnabled(bool $whatsappEnabled): static
    {
        $this->whatsappEnabled = $whatsappEnabled;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getLinkedinUrl(): ?string
    {
        return $this->linkedinUrl;
    }

    public function setLinkedinUrl(?string $linkedinUrl): static
    {
        $this->linkedinUrl = $linkedinUrl;

        return $this;
    }

    public function getGithubUrl(): ?string
    {
        return $this->githubUrl;
    }

    public function setGithubUrl(?string $githubUrl): static
    {
        $this->githubUrl = $githubUrl;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }

    public function getPortfolioUrl(): ?string
    {
        return $this->portfolioUrl;
    }

    public function setPortfolioUrl(?string $portfolioUrl): static
    {
        $this->portfolioUrl = $portfolioUrl;

        return $this;
    }

    public function getAvailability(): ?Availability
    {
        return $this->availability;
    }

    public function setAvailability(?Availability $availability): static
    {
        $this->availability = $availability;

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

    /** @return Collection<int, UserInstitution> */
    public function getInstitutionAttachments(): Collection
    {
        return $this->institutionAttachments;
    }

    public function addInstitutionAttachment(UserInstitution $attachment): static
    {
        if (!$this->institutionAttachments->contains($attachment)) {
            $this->institutionAttachments->add($attachment);
            $attachment->setUser($this);
        }

        return $this;
    }

    public function removeInstitutionAttachment(UserInstitution $attachment): static
    {
        $this->institutionAttachments->removeElement($attachment);

        return $this;
    }

    /**
     * Rattachement actif existant pour ce contexte, s'il y en a déjà un
     * (utilisé pour éviter les doublons lors d'une nouvelle sélection).
     */
    public function getInstitutionAttachment(InstitutionContext $context): ?UserInstitution
    {
        foreach ($this->institutionAttachments as $attachment) {
            if ($attachment->getContext() === $context && $attachment->isActive()) {
                return $attachment;
            }
        }

        return null;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getFacebookId(): ?string
    {
        return $this->facebookId;
    }

    public function setFacebookId(?string $facebookId): static
    {
        $this->facebookId = $facebookId;

        return $this;
    }

    public function getLinkedinId(): ?string
    {
        return $this->linkedinId;
    }

    public function setLinkedinId(?string $linkedinId): static
    {
        $this->linkedinId = $linkedinId;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Skill> */
    public function getSkills(): Collection
    {
        return $this->skills;
    }

    public function addSkill(Skill $skill): static
    {
        if (!$this->skills->contains($skill)) {
            $this->skills->add($skill);
        }

        return $this;
    }

    public function removeSkill(Skill $skill): static
    {
        $this->skills->removeElement($skill);

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

    /** @return Collection<int, Project> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    /** @return Collection<int, Experience> */
    public function getExperiences(): Collection
    {
        return $this->experiences;
    }

    public function addExperience(Experience $experience): static
    {
        if (!$this->experiences->contains($experience)) {
            $this->experiences->add($experience);
            $experience->setUser($this);
        }

        return $this;
    }

    public function removeExperience(Experience $experience): static
    {
        $this->experiences->removeElement($experience);

        return $this;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
