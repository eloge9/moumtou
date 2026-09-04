<?php

namespace App\Service;

use App\Entity\AnalyticsEvent;
use App\Entity\ContactRequest;
use App\Entity\ErrorLog;
use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\RecruiterFavorite;
use App\Entity\TalentView;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suppression définitive d'un compte par un administrateur (règle 9/10) :
 * anonymisation irréversible, PAS une suppression physique de la ligne
 * `app_user` — le même principe que la suppression volontaire déjà en place
 * ({@see \App\Controller\AccountController::delete()}), en plus approfondi
 * (données de rôle également effacées) et déclenché par un administrateur.
 *
 * Choix motivé (analysé relation par relation, cahier §26/§29) : garder la
 * ligne `app_user` évite de casser les 20+ références qui y pointent
 * (projets publiés, commentaires, évaluations, soutenances, jury, journal
 * d'audit…) — ces contenus restent publiquement intègres, affichés sous
 * « Compte supprimé », sans URL cassée ni QR code invalidé (§29/§30).
 * Seules les données strictement privées à ce compte (notifications,
 * préférences, historique de consultation/favoris recruteur, demandes de
 * contact, rattachements d'établissement, expériences, profil recruteur)
 * sont réellement supprimées ci-dessous.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function delete(User $user): void
    {
        $id = $user->getId();

        // 1. Données personnelles identifiantes — même liste que la
        // suppression volontaire (AccountController::delete()).
        $user->setFirstName('Compte');
        $user->setLastName('supprimé');
        $user->setEmail(sprintf('compte-supprime-%d@moumtou.invalid', $id));
        $user->setPhone('+000000000');
        $user->setWhatsapp(null);
        $user->setWhatsappEnabled(false);
        $user->setPhoto(null);
        $user->setBio(null);
        $user->setLinkedinUrl(null);
        $user->setGithubUrl(null);
        $user->setWebsiteUrl(null);
        $user->setPortfolioUrl(null);
        $user->setCountry(null);
        $user->setCity(null);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));

        // 2. Données de rôle/cursus — plus loin que la suppression
        // volontaire : un administrateur supprime un compte de façon plus
        // complète (§10 : « traiter correctement toutes les relations »).
        $user->setProfessionalTitle(null);
        $user->setInstitution(null);
        $user->setDomain(null);
        $user->setMention(null);
        $user->setSpecialty(null);
        $user->setAvailability(null);
        $user->setGoogleId(null);
        $user->setFacebookId(null);
        $user->setLinkedinId(null);
        foreach ($user->getSkills() as $skill) {
            $user->removeSkill($skill);
        }
        foreach ($user->getTechnologies() as $technology) {
            $user->removeTechnology($technology);
        }

        // 3. Contenu strictement privé à ce compte, sans valeur publique une
        // fois le compte supprimé : suppression réelle (pas d'anonymisation
        // possible pour la plupart — clé étrangère non nullable).
        $recruiterProfile = $user->getRecruiterProfile();
        if ($recruiterProfile) {
            $user->setRecruiterProfile(null);
            $this->em->remove($recruiterProfile);
        }
        foreach ($user->getInstitutionAttachments()->toArray() as $attachment) {
            $this->em->remove($attachment);
        }
        foreach ($user->getExperiences()->toArray() as $experience) {
            $this->em->remove($experience);
        }
        $this->em->createQuery('DELETE FROM '.Notification::class.' n WHERE n.recipient = :user')->setParameter('user', $user)->execute();
        $this->em->createQuery('DELETE FROM '.NotificationPreference::class.' p WHERE p.user = :user')->setParameter('user', $user)->execute();
        $this->em->createQuery('DELETE FROM '.TalentView::class.' v WHERE v.talent = :user OR v.recruiter = :user')->setParameter('user', $user)->execute();
        $this->em->createQuery('DELETE FROM '.RecruiterFavorite::class.' f WHERE f.talent = :user OR f.recruiter = :user')->setParameter('user', $user)->execute();
        $this->em->createQuery('DELETE FROM '.ContactRequest::class.' c WHERE c.talent = :user OR c.recruiter = :user')->setParameter('user', $user)->execute();

        // 4. Statistiques : détachées (anonymisées), jamais supprimées —
        // l'agrégat garde sa valeur, la personne n'est plus identifiable.
        $this->em->createQuery('UPDATE '.AnalyticsEvent::class.' a SET a.user = NULL WHERE a.user = :user')->setParameter('user', $user)->execute();
        $this->em->createQuery('UPDATE '.ErrorLog::class.' e SET e.user = NULL WHERE e.user = :user')->setParameter('user', $user)->execute();

        // 5. Contenu public/historique (projets, commentaires, évaluations,
        // soutenances, jury, signalements, sanctions, vérifications,
        // journal d'administration) : conservé tel quel — la ligne `app_user`
        // reste, désormais anonymisée, donc ces références restent valides
        // et s'affichent sous « Compte supprimé » (§29, stratégie déjà en
        // place réutilisée plutôt qu'une suppression en cascade qui
        // casserait des URLs publiques et l'intégrité de l'historique).

        $user->setStatus(UserStatus::SUPPRIME);
        $this->em->flush();
    }
}
