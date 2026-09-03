<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\VerificationEvent;
use App\Entity\VerificationRequest;
use App\Enum\AdminAuditAction;
use App\Enum\NotificationType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ReportTargetType;
use App\Enum\VerificationStatus;
use App\Repository\VerificationRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Point central du système de vérification (cahier des charges —
 * FONCTIONNALITÉ 14) : couche indépendante de la modération
 * ({@see \App\Enum\ProjectStatus}, §19) et du système de soutenance/jury
 * (§20, non touché ici — {@see DefenseValidator} reste l'unique source de
 * vérité pour la vérification d'une soutenance).
 *
 * Toute transition de statut passe par ici, que ce soit depuis le nouvel
 * espace "Vérifications" ou depuis le raccourci de modération déjà existant
 * sur la fiche projet (cahier §15 : ne pas dupliquer la traçabilité).
 */
class VerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VerificationRequestRepository $requestRepository,
        private readonly NotificationService $notificationService,
        private readonly AdminAuditLogger $auditLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return string[] conditions manquantes (vide = éligible) — cahier §7
     */
    public function eligibilityForProject(Project $project): array
    {
        $missing = [];

        if (!\in_array($project->getStatus(), [ProjectStatus::PUBLIE], true)) {
            $missing[] = 'Le projet doit être publié avant de pouvoir demander sa vérification.';
        }
        if (!$project->getShortDescription() && !$project->getDetailedDescription()) {
            $missing[] = 'Le projet doit avoir une description.';
        }
        if (!$project->getType()) {
            $missing[] = 'Le type de projet doit être défini.';
        }

        if (ProjectType::SOUTENANCE === $project->getType()) {
            if (!$project->getInstitution()) {
                $missing[] = 'L\'établissement doit être renseigné pour un projet académique.';
            }
            if (!$project->getDefense()) {
                $missing[] = 'La soutenance doit être renseignée pour un projet académique.';
            }
        } elseif (0 === $project->getProofs()->count() && 0 === $project->getDocuments()->count()) {
            $missing[] = 'Au moins une preuve (GitHub, site, démo, vidéo, document…) est nécessaire.';
        }

        return $missing;
    }

    /**
     * @return string[] conditions manquantes (vide = éligible) — cahier §24 :
     *                   ne demande jamais de document sensible.
     */
    public function eligibilityForProfile(User $user): array
    {
        $missing = [];

        if (!$user->getBio() && !$user->getProfessionalTitle()) {
            $missing[] = 'Ajoutez une bio ou un titre professionnel à votre profil.';
        }

        $hasPublishedProject = false;
        foreach ($user->getProjects() as $project) {
            if (\in_array($project->getStatus(), [ProjectStatus::PUBLIE, ProjectStatus::VERIFIE], true)) {
                $hasPublishedProject = true;
                break;
            }
        }
        $hasExternalLink = $user->getLinkedinUrl() || $user->getGithubUrl() || $user->getWebsiteUrl();

        if (!$hasPublishedProject && !$hasExternalLink) {
            $missing[] = 'Publiez au moins un projet ou renseignez un lien externe (LinkedIn, GitHub, site) vérifiable.';
        }

        return $missing;
    }

    public function requestProjectVerification(Project $project, User $requester): VerificationRequest
    {
        return $this->createOrReopen(ReportTargetType::PROJECT, $project->getId(), $requester);
    }

    public function requestProfileVerification(User $user): VerificationRequest
    {
        return $this->createOrReopen(ReportTargetType::PROFILE, $user->getId(), $user);
    }

    private function createOrReopen(ReportTargetType $targetType, int $targetId, User $requester): VerificationRequest
    {
        $existing = $this->requestRepository->findLatestForTarget($targetType, $targetId);
        if ($existing && $existing->getStatus()->isOpen()) {
            return $existing;
        }

        $request = new VerificationRequest();
        $request->setTargetType($targetType);
        $request->setTargetId($targetId);
        $request->setRequester($requester);
        $request->setStatus(VerificationStatus::EN_ATTENTE);
        $this->em->persist($request);

        $this->logEvent($request, $requester, null, VerificationStatus::EN_ATTENTE, 'Demande créée');
        $this->em->flush();

        $this->notify(
            $requester,
            ReportTargetType::PROJECT === $targetType ? NotificationType::PROJECT_VERIFICATION_REQUESTED : NotificationType::PROFILE_VERIFICATION_REQUESTED,
            'Votre demande de vérification a été reçue.',
            $targetType,
            $targetId,
        );

        return $request;
    }

    /** Le demandeur soumet à nouveau après une correction demandée (cahier §23). */
    public function resubmit(VerificationRequest $request): void
    {
        if (VerificationStatus::CORRECTION_DEMANDEE !== $request->getStatus()) {
            throw new \LogicException('Seule une demande en correction peut être resoumise.');
        }

        $previous = $request->getStatus();
        $request->setStatus(VerificationStatus::EN_ATTENTE);
        $this->logEvent($request, $request->getRequester(), $previous, VerificationStatus::EN_ATTENTE, 'Nouvelle soumission');
        $this->em->flush();
    }

    public function claim(VerificationRequest $request, User $admin): void
    {
        $previous = $request->getStatus();
        $request->setStatus(VerificationStatus::EN_VERIFICATION);
        $request->setReviewer($admin);
        $this->logEvent($request, $admin, $previous, VerificationStatus::EN_VERIFICATION, 'Demande prise en charge par '.$admin->getFullName());
        $this->em->flush();

        $this->auditLogger->log($admin, AdminAuditAction::VERIFICATION_REQUEST_CLAIMED, $request->getTargetType()->value, $request->getTargetId());

        $this->notify(
            $request->getRequester(),
            ReportTargetType::PROJECT === $request->getTargetType() ? NotificationType::PROJECT_VERIFICATION_IN_REVIEW : NotificationType::PROFILE_VERIFICATION_IN_REVIEW,
            'Votre demande de vérification est en cours d\'examen.',
            $request->getTargetType(),
            $request->getTargetId(),
        );
    }

    public function approve(VerificationRequest $request, User $admin, ?string $comment = null): void
    {
        $previous = $request->getStatus();
        $request->setStatus(VerificationStatus::VERIFIEE);
        $request->setReviewer($admin);
        $request->setReason($comment);
        $request->setDecidedAt(new \DateTimeImmutable());
        $this->logEvent($request, $admin, $previous, VerificationStatus::VERIFIEE, $comment);

        $target = $this->applyToTarget($request, $admin, true);
        $this->em->flush();

        if ($target instanceof Project) {
            $this->auditLogger->log($admin, AdminAuditAction::PROJECT_VERIFIED, 'Project', $target->getId(), $target->getName());
            $this->notify($request->getRequester(), NotificationType::PROJECT_VERIFIED, 'Votre projet a été vérifié.', $request->getTargetType(), $request->getTargetId());
        } elseif ($target instanceof User) {
            $this->auditLogger->log($admin, AdminAuditAction::PROFILE_VERIFIED, 'User', $target->getId(), $target->getFullName());
            $this->notify($request->getRequester(), NotificationType::PROFILE_VERIFIED, 'Votre profil a été vérifié.', $request->getTargetType(), $request->getTargetId());
        }
    }

    public function requestCorrection(VerificationRequest $request, User $admin, string $reason): void
    {
        $previous = $request->getStatus();
        $request->setStatus(VerificationStatus::CORRECTION_DEMANDEE);
        $request->setReviewer($admin);
        $request->setReason($reason);
        $this->logEvent($request, $admin, $previous, VerificationStatus::CORRECTION_DEMANDEE, $reason);
        $this->em->flush();

        $this->auditLogger->log($admin, AdminAuditAction::CORRECTION_REQUESTED, $request->getTargetType()->value, $request->getTargetId(), null, $reason);

        $this->notify(
            $request->getRequester(),
            ReportTargetType::PROJECT === $request->getTargetType() ? NotificationType::PROJECT_CORRECTION_REQUESTED : NotificationType::PROFILE_CORRECTION_REQUESTED,
            'Des corrections sont nécessaires avant la vérification.',
            $request->getTargetType(),
            $request->getTargetId(),
        );
    }

    public function reject(VerificationRequest $request, User $admin, string $reason): void
    {
        $previous = $request->getStatus();
        $request->setStatus(VerificationStatus::REFUSEE);
        $request->setReviewer($admin);
        $request->setReason($reason);
        $request->setDecidedAt(new \DateTimeImmutable());
        $this->logEvent($request, $admin, $previous, VerificationStatus::REFUSEE, $reason);
        $this->em->flush();

        $this->auditLogger->log($admin, AdminAuditAction::VERIFICATION_REQUEST_REJECTED, $request->getTargetType()->value, $request->getTargetId(), null, $reason);

        $this->notify(
            $request->getRequester(),
            ReportTargetType::PROJECT === $request->getTargetType() ? NotificationType::PROJECT_VERIFICATION_REFUSED : NotificationType::PROFILE_VERIFICATION_REFUSED,
            'Votre demande de vérification a été refusée.',
            $request->getTargetType(),
            $request->getTargetId(),
        );
    }

    /** Uniquement possible sur une demande déjà VERIFIEE (cahier §12). */
    public function revoke(VerificationRequest $request, User $admin, string $reason): void
    {
        if (VerificationStatus::VERIFIEE !== $request->getStatus()) {
            throw new \LogicException('Seule une vérification accordée peut être retirée.');
        }

        $previous = $request->getStatus();
        $request->setStatus(VerificationStatus::RETIREE);
        $request->setReviewer($admin);
        $request->setReason($reason);
        $request->setDecidedAt(new \DateTimeImmutable());
        $this->logEvent($request, $admin, $previous, VerificationStatus::RETIREE, $reason);

        $target = $this->applyToTarget($request, $admin, false);
        $this->em->flush();

        if ($target instanceof Project) {
            $this->auditLogger->log($admin, AdminAuditAction::PROJECT_UNVERIFIED, 'Project', $target->getId(), $target->getName(), $reason);
            $this->notify($request->getRequester(), NotificationType::PROJECT_VERIFICATION_REVOKED, 'La vérification de votre projet a été retirée.', $request->getTargetType(), $request->getTargetId());
        } elseif ($target instanceof User) {
            $this->auditLogger->log($admin, AdminAuditAction::PROFILE_UNVERIFIED, 'User', $target->getId(), $target->getFullName(), $reason);
            $this->notify($request->getRequester(), NotificationType::PROFILE_VERIFICATION_REVOKED, 'La vérification de votre profil a été retirée.', $request->getTargetType(), $request->getTargetId());
        }
    }

    /**
     * Retrait automatique (pas une décision admin) lorsqu'un projet vérifié
     * est substantiellement modifié par son propriétaire (cahier §15/§23 —
     * comportement déjà existant sur {@see Project::setStatus()}, ici
     * répercuté sur la demande de vérification associée). N'écrit jamais
     * dans {@see AdminAuditLog} : aucun administrateur n'est à l'origine de
     * cette transition.
     */
    public function revokeAfterSubstantialEdit(VerificationRequest $request, User $owner): void
    {
        if (VerificationStatus::VERIFIEE !== $request->getStatus()) {
            return;
        }

        $previous = $request->getStatus();
        $note = 'Vérification retirée automatiquement : le projet a été modifié substantiellement après sa vérification.';
        $request->setStatus(VerificationStatus::RETIREE);
        $request->setReason($note);
        $request->setDecidedAt(new \DateTimeImmutable());
        $this->logEvent($request, $owner, $previous, VerificationStatus::RETIREE, $note);
        $this->em->flush();

        $this->notify($owner, NotificationType::PROJECT_VERIFICATION_REVOKED, $note, ReportTargetType::PROJECT, $request->getTargetId());
    }

    /**
     * Garde en cohérence une demande existante lorsque l'admin agit depuis le
     * raccourci de modération déjà présent sur la fiche projet plutôt que
     * depuis l'espace "Vérifications" (cahier §15 : une seule traçabilité).
     * Ne notifie pas et n'audite pas : l'appelant l'a déjà fait pour son
     * action de modération.
     */
    public function syncFromQuickModeration(Project $project, VerificationStatus $newStatus, User $admin, ?string $note): void
    {
        $request = $this->requestRepository->findLatestForTarget(ReportTargetType::PROJECT, $project->getId());
        if (!$request || $request->getStatus() === $newStatus) {
            return;
        }

        $previous = $request->getStatus();
        $request->setStatus($newStatus);
        $request->setReviewer($admin);
        if (\in_array($newStatus, [VerificationStatus::VERIFIEE, VerificationStatus::REFUSEE, VerificationStatus::RETIREE], true)) {
            $request->setDecidedAt(new \DateTimeImmutable());
        }
        $this->logEvent($request, $admin, $previous, $newStatus, $note ?: 'Synchronisé depuis la fiche projet (action rapide de modération)');
        $this->em->flush();
    }

    /**
     * @return Project|User|null la cible mise à jour, pour construire les
     *                           journaux/notifications appelants
     */
    private function applyToTarget(VerificationRequest $request, User $admin, bool $verified): Project|User|null
    {
        if (ReportTargetType::PROJECT === $request->getTargetType()) {
            $project = $this->em->getRepository(Project::class)->find($request->getTargetId());
            if (!$project) {
                return null;
            }
            if ($verified) {
                $project->setStatus(ProjectStatus::VERIFIE);
                $project->setVerifiedAt(new \DateTimeImmutable());
                $project->setVerifiedBy($admin);
            } else {
                if (ProjectStatus::VERIFIE === $project->getStatus()) {
                    $project->setStatus(ProjectStatus::PUBLIE);
                }
                $project->setVerifiedAt(null);
                $project->setVerifiedBy(null);
            }

            return $project;
        }

        $user = $this->em->getRepository(User::class)->find($request->getTargetId());
        if (!$user) {
            return null;
        }
        if ($verified) {
            $user->setProfileVerified(true);
            $user->setProfileVerifiedAt(new \DateTimeImmutable());
            $user->setProfileVerifiedBy($admin);
        } else {
            $user->setProfileVerified(false);
            $user->setProfileVerifiedAt(null);
            $user->setProfileVerifiedBy(null);
        }

        return $user;
    }

    private function logEvent(VerificationRequest $request, User $actor, ?VerificationStatus $previous, VerificationStatus $new, ?string $note): void
    {
        $event = new VerificationEvent();
        $event->setActor($actor);
        $event->setPreviousStatus($previous);
        $event->setNewStatus($new);
        $event->setNote($note);
        $request->addEvent($event);
        $this->em->persist($event);
    }

    private function notify(User $recipient, NotificationType $type, string $message, ReportTargetType $targetType, int $targetId): void
    {
        $actionUrl = null;
        if (ReportTargetType::PROJECT === $targetType) {
            $project = $this->em->getRepository(Project::class)->find($targetId);
            if ($project?->getSlug()) {
                $actionUrl = $this->urlGenerator->generate('app_project_show', ['slug' => $project->getSlug()]);
            }
        } else {
            $actionUrl = $this->urlGenerator->generate('app_profile_edit');
        }

        $this->notificationService->notify($recipient, $type, $type->label(), $message, $actionUrl);
    }
}
