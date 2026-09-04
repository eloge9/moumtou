<?php

namespace App\Service;

use App\Entity\Defense;
use App\Entity\DefenseValidation;
use App\Entity\JuryMember;
use App\Enum\DefenseStatus;
use App\Enum\NotificationType;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Point central de la règle "au moins 2 membres du jury doivent valider"
 * (cahier des charges §19) : une soutenance ne devient VERIFIEE que
 * lorsque ce seuil est atteint — jamais après une seule confirmation.
 * Utilisé aussi bien par le lien signé public que par l'espace enseignant
 * connecté, pour ne jamais dupliquer cette logique.
 */
class DefenseValidator
{
    public const MIN_VALIDATIONS = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Enregistre la confirmation d'un membre du jury que la soutenance a
     * réellement eu lieu. Retourne false sans rien faire si ce membre a
     * déjà validé (empêche toute double validation).
     */
    public function recordValidation(JuryMember $juryMember, ?string $ipAddress = null): bool
    {
        $defense = $juryMember->getDefense();

        $existing = $this->em->getRepository(DefenseValidation::class)->findOneBy([
            'defense' => $defense,
            'juryMember' => $juryMember,
        ]);
        if ($existing) {
            return false;
        }

        $validation = new DefenseValidation();
        $validation->setDefense($defense);
        $validation->setJuryMember($juryMember);
        $validation->setIpAddress($ipAddress);
        $this->em->persist($validation);
        $this->em->flush();

        $this->refreshVerificationStatus($defense);

        return true;
    }

    /**
     * Fait passer la soutenance (et le projet) à VERIFIEE si le seuil est
     * atteint. Ne redescend jamais un statut déjà vérifié.
     */
    public function refreshVerificationStatus(Defense $defense): void
    {
        if (DefenseStatus::VERIFIEE === $defense->getStatus()) {
            return;
        }

        if ($defense->getValidationCount() < self::MIN_VALIDATIONS) {
            return;
        }

        $this->markVerified($defense, 'Votre soutenance pour "%s" a été vérifiée par le jury.');
    }

    /**
     * Vérification directe par un administrateur (cahier — gestion des
     * soutenances §25 : « Admin peut consulter/modérer toutes les
     * soutenances »), sans attendre les 2 confirmations du jury — réservée
     * aux cas où le jury ne peut/ne va pas confirmer lui-même (ex. jury
     * externe non inscrit, soutenance ancienne). Toujours journalisée
     * ({@see \App\Enum\AdminAuditAction::DEFENSE_VERIFIED_BY_ADMIN}) par
     * l'appelant, jamais silencieuse.
     */
    public function forceVerifyByAdmin(Defense $defense): void
    {
        if (DefenseStatus::VERIFIEE === $defense->getStatus()) {
            return;
        }

        $this->markVerified($defense, 'Votre soutenance pour "%s" a été vérifiée par l\'administration.');
    }

    private function markVerified(Defense $defense, string $notificationMessageTemplate): void
    {
        $defense->setStatus(DefenseStatus::VERIFIEE);
        $defense->setVerifiedAt(new \DateTimeImmutable());
        $project = $defense->getProject();
        $project->setStatus(ProjectStatus::VERIFIE);
        $project->setVerifiedAt($defense->getVerifiedAt());
        $this->em->flush();

        $this->notificationService->notify(
            $project->getOwner(),
            NotificationType::DEFENSE_VERIFIED,
            'Soutenance vérifiée',
            \sprintf($notificationMessageTemplate, $project->getName()),
            $this->urlGenerator->generate('app_defense_manage'),
        );
    }
}
