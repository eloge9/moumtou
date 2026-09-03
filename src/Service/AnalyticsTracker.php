<?php

namespace App\Service;

use App\Entity\AnalyticsEvent;
use App\Entity\Project;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\AnalyticsEventType;
use App\Enum\ProofType;
use App\Repository\AnalyticsEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Point d'écriture unique des événements analytics (cahier des charges —
 * FONCTIONNALITÉ 12 §28) : toute mesure passe par ici, jamais par une
 * insertion dispersée dans les contrôleurs — à l'image de
 * {@see AdminAuditLogger} pour le journal d'administration.
 *
 * Confidentialité (§2/§5/§6) : ne stocke jamais d'adresse IP. Le visiteur
 * anonyme est identifié uniquement par un hachage non réversible de
 * l'identifiant de session déjà utilisé ailleurs dans l'application (aucun
 * nouveau cookie créé) ; l'utilisateur authentifié est identifié par son
 * compte. Une fenêtre de 30 minutes protège les vues contre le rechargement
 * répété d'une même personne (anti-abus §6), sans empêcher un vrai retour
 * ultérieur d'incrémenter à nouveau les vues totales.
 */
class AnalyticsTracker
{
    private const DEDUP_WINDOW_MINUTES = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsEventRepository $repository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Enregistre une vue de la page publique d'un projet, avec déduplication
     * anti-abus. `$source` vaut 'qr' ou 'direct' (cahier §7/§8).
     */
    public function trackProjectView(Project $project, ?User $user, string $source): void
    {
        $visitorHash = $this->visitorHash();
        $since = new \DateTimeImmutable(sprintf('-%d minutes', self::DEDUP_WINDOW_MINUTES));

        if ($this->repository->hasRecentView($project, $user, $visitorHash, $since)) {
            return;
        }

        $this->record(AnalyticsEventType::PROJECT_VIEW, $project, $user, \in_array($source, ['qr', 'direct'], true) ? $source : 'direct');
    }

    public function trackShare(Project $project, ?User $user): void
    {
        $this->record(AnalyticsEventType::PROJECT_SHARE, $project, $user);
    }

    public function trackProofClick(Project $project, ?User $user, ProofType $proofType): void
    {
        $this->record(AnalyticsEventType::PROOF_CLICK, $project, $user, $proofType->value);
    }

    public function trackQrDownload(Project $project, ?User $user, string $format): void
    {
        $this->record(AnalyticsEventType::QR_DOWNLOAD, $project, $user, \in_array($format, ['svg', 'png'], true) ? $format : null);
    }

    public function trackYoutubeOpen(Project $project, ?User $user): void
    {
        $this->record(AnalyticsEventType::YOUTUBE_OPEN, $project, $user);
    }

    /**
     * Comptabilise une recherche filtrée par technologie (cahier §19),
     * strictement anonyme et agrégée : ni utilisateur ni visiteur ne sont
     * enregistrés, seul l'identifiant de la technologie déjà référencée
     * dans le catalogue est conservé — jamais la requête textuelle libre
     * saisie par la personne.
     */
    public function trackTechnologySearch(Technology $technology): void
    {
        $event = new AnalyticsEvent();
        $event->setType(AnalyticsEventType::TECHNOLOGY_SEARCH);
        $event->setMetadata((string) $technology->getId());

        $this->em->persist($event);
        $this->em->flush();
    }

    private function record(AnalyticsEventType $type, ?Project $project, ?User $user, ?string $metadata = null): void
    {
        $event = new AnalyticsEvent();
        $event->setType($type);
        $event->setProject($project);
        $event->setUser($user);
        $event->setVisitorHash($this->visitorHash());
        $event->setMetadata($metadata);

        $this->em->persist($event);
        $this->em->flush();
    }

    /**
     * Hache l'identifiant de session courant (jamais stocké en clair) —
     * réutilise la session déjà démarrée pour d'autres besoins (ex. le
     * suivi "projets déjà vus" de la FONCTIONNALITÉ 3) plutôt que de créer
     * un second mécanisme de suivi.
     */
    private function visitorHash(): string
    {
        $session = $this->requestStack->getSession();

        return hash('sha256', $session->getId());
    }
}
