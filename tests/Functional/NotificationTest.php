<?php

namespace App\Tests\Functional;

use App\Entity\ContactRequest;
use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Enum\NotificationType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 8 : notifications & centre de
 * notifications (création, sécurité, marquage, intégrations métier,
 * préférences).
 */
class NotificationTest extends FunctionalTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)->setFirstName('Test')->setLastName(ucfirst($slug))
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function makeProject(EntityManagerInterface $em, User $owner, string $name, string $slug): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);

        return $project;
    }

    private function makeNotification(EntityManagerInterface $em, User $recipient, string $title = 'Titre'): Notification
    {
        $notification = new Notification();
        $notification->setRecipient($recipient);
        $notification->setType(NotificationType::COMMENT_RECEIVED);
        $notification->setTitle($title);
        $notification->setMessage('Message.');
        $em->persist($notification);

        return $notification;
    }

    public function testNotificationIsCreatedForTheCorrectRecipient(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.notif1@example.com', 'owner-notif1');
        $project = $this->makeProject($em, $owner, 'Projet notif', 'projet-notif1');
        $commenter = $this->makeUser($em, 'commenter.notif1@example.com', 'commenter-notif1');
        $em->flush();

        $client->loginUser($commenter);
        $crawler = $client->request('GET', '/projets/projet-notif1');
        $token = $crawler->filter('#modale-commentaire input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-notif1/commenter', ['content' => 'Bravo !', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $notification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $owner]);
        self::assertNotNull($notification);
        self::assertSame(NotificationType::COMMENT_RECEIVED, $notification->getType());
        self::assertFalse($notification->isRead());
        self::assertNull($em->getRepository(Notification::class)->findOneBy(['recipient' => $commenter]), 'Le commentateur ne doit pas se notifier lui-même.');
    }

    public function testUserDoesNotSeeAnotherUsersNotifications(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $userA = $this->makeUser($em, 'user.a@example.com', 'user-a-notif');
        $userB = $this->makeUser($em, 'user.b@example.com', 'user-b-notif');
        $this->makeNotification($em, $userB, 'Notification de B');
        $em->flush();

        $client->loginUser($userA);
        $crawler = $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Notification de B');
        self::assertSelectorTextContains('body', 'Aucune notification');
    }

    public function testUserCannotMarkOrDeleteAnotherUsersNotification(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $userA = $this->makeUser($em, 'user.idorA@example.com', 'user-idorA');
        $userB = $this->makeUser($em, 'user.idorB@example.com', 'user-idorB');
        $notification = $this->makeNotification($em, $userB);
        $em->flush();
        $notificationId = $notification->getId();

        $client->loginUser($userA);
        $client->request('POST', '/notifications/'.$notificationId.'/lire', ['_csrf_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/notifications/'.$notificationId.'/supprimer', ['_csrf_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/notifications/'.$notificationId.'/ouvrir');
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertFalse($em->getRepository(Notification::class)->find($notificationId)->isRead());
    }

    public function testMarkAsReadAndUnreadCount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->makeUser($em, 'user.read@example.com', 'user-read');
        $n1 = $this->makeNotification($em, $user, 'Première');
        $n2 = $this->makeNotification($em, $user, 'Deuxième');
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notifications');
        self::assertSelectorTextContains('body', '🔔 2');

        $token = $crawler->filter('form[action="/notifications/'.$n1->getId().'/lire"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/notifications/'.$n1->getId().'/lire', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->getRepository(Notification::class)->find($n1->getId())->isRead());
        self::assertFalse($em->getRepository(Notification::class)->find($n2->getId())->isRead());

        $crawler = $client->request('GET', '/notifications');
        self::assertSelectorTextContains('body', '🔔 1');
    }

    public function testMarkAllAsRead(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->makeUser($em, 'user.readall@example.com', 'user-readall');
        $this->makeNotification($em, $user, 'Une');
        $this->makeNotification($em, $user, 'Deux');
        $this->makeNotification($em, $user, 'Trois');
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notifications');
        $token = $crawler->filter('form[action="/notifications/tout-lire"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/notifications/tout-lire', ['_csrf_token' => $token]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(Notification::class)->countUnread($user));
    }

    public function testOpeningANotificationMarksItReadAndRedirectsToActionUrl(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->makeUser($em, 'user.open@example.com', 'user-open');
        $notification = $this->makeNotification($em, $user);
        $notification->setActionUrl('/explorer');
        $em->flush();
        $id = $notification->getId();

        $client->loginUser($user);
        $client->request('GET', '/notifications/'.$id.'/ouvrir');
        self::assertResponseRedirects('/explorer');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->getRepository(Notification::class)->find($id)->isRead());
    }

    public function testEmptyStateWhenNoNotifications(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->makeUser($em, 'user.empty@example.com', 'user-empty');
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune notification');
        self::assertSelectorTextContains('body', 'Vous êtes à jour');
    }

    public function testAnonymousCannotAccessNotificationCenter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notifications');
        self::assertResponseRedirects('/connexion');
    }

    public function testFullContactRequestNotificationFlow(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.flow@example.com', 'talent-flow');
        $this->makeProject($em, $talent, 'Projet flow', 'projet-flow');
        $recruiter = $this->makeUser($em, 'recruiter.flow@example.com', 'recruiter-flow', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/profils/talent-flow');
        $token = $crawler->filter('form[action="/recruteur/demandes/'.$talent->getId().'/envoyer"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/recruteur/demandes/'.$talent->getId().'/envoyer', ['message' => 'Bonjour', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $talentNotification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $talent, 'type' => NotificationType::CONTACT_REQUEST_RECEIVED]);
        self::assertNotNull($talentNotification, 'Le talent doit être notifié de la nouvelle demande.');

        $contactRequest = $em->getRepository(ContactRequest::class)->findOneBy(['recruiter' => $recruiter, 'talent' => $talent]);

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-espace-talent/demandes');
        $acceptToken = $crawler->filter('form[action="/mon-espace-talent/demandes/'.$contactRequest->getId().'/accepter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/mon-espace-talent/demandes/'.$contactRequest->getId().'/accepter', ['_csrf_token' => $acceptToken]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $recruiterNotification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $recruiter, 'type' => NotificationType::CONTACT_REQUEST_ACCEPTED]);
        self::assertNotNull($recruiterNotification, 'Le recruteur doit être notifié de l\'acceptation.');
    }

    public function testProjectVerifiedNotifiesOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.verif@example.com', 'owner-verif');
        $project = $this->makeProject($em, $owner, 'Projet a verifier', 'projet-a-verifier');
        $admin = $this->makeUser($em, 'admin.verif@example.com', 'admin-verif', ['ROLE_ADMIN']);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$project->getId());
        $token = $crawler->filter('form[action="/admin/moderation/projets/'.$project->getId().'/decider"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/moderation/projets/'.$project->getId().'/decider', [
            'action' => 'marquer_verifie',
            '_csrf_token' => $token,
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $notification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $owner, 'type' => NotificationType::PROJECT_VERIFIED]);
        self::assertNotNull($notification);
        self::assertStringContainsString('Projet a verifier', $notification->getMessage());
    }

    public function testAccountSuspensionNotifiesUserWithoutExposingAdminDetails(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $target = $this->makeUser($em, 'target.sanction@example.com', 'target-sanction');
        $admin = $this->makeUser($em, 'admin.sanction@example.com', 'admin-sanction', ['ROLE_ADMIN']);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs');
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$target->getId().'/sanctionner"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/utilisateurs/'.$target->getId().'/sanctionner', [
            'action' => 'suspendre_7',
            'reason' => 'Comportement non conforme.',
            '_csrf_token' => $token,
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $notification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $target, 'type' => NotificationType::ACCOUNT_SUSPENDED]);
        self::assertNotNull($notification);
        self::assertStringContainsString('Comportement non conforme', $notification->getMessage());
        self::assertStringNotContainsString('admin-sanction', $notification->getMessage());
    }

    public function testSecurityPreferenceCannotBeDisabled(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $target = $this->makeUser($em, 'target.forced@example.com', 'target-forced');
        // Tente explicitement de désactiver la catégorie sécurité — sans effet.
        $preference = new NotificationPreference();
        $preference->setUser($target)->setCategory(NotificationCategory::SECURITE)->setInAppEnabled(false)->setEmailEnabled(false);
        $em->persist($preference);
        $admin = $this->makeUser($em, 'admin.forced@example.com', 'admin-forced', ['ROLE_ADMIN']);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs');
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$target->getId().'/sanctionner"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/utilisateurs/'.$target->getId().'/sanctionner', [
            'action' => 'avertir',
            'reason' => 'Test.',
            '_csrf_token' => $token,
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($em->getRepository(Notification::class)->findOneBy(['recipient' => $target, 'type' => NotificationType::ACCOUNT_WARNED]), 'Les notifications de sécurité ne doivent jamais pouvoir être désactivées.');
    }

    public function testDisablingInAppPreferenceStopsNotificationCreation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.pref@example.com', 'owner-pref');
        $project = $this->makeProject($em, $owner, 'Projet pref', 'projet-pref');
        $preference = new NotificationPreference();
        $preference->setUser($owner)->setCategory(NotificationCategory::COMMUNAUTE)->setInAppEnabled(false)->setEmailEnabled(false);
        $em->persist($preference);
        $commenter = $this->makeUser($em, 'commenter.pref@example.com', 'commenter-pref');
        $em->flush();

        $client->loginUser($commenter);
        $crawler = $client->request('GET', '/projets/projet-pref');
        $token = $crawler->filter('#modale-commentaire input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-pref/commenter', ['content' => 'Un avis.', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Notification::class)->findOneBy(['recipient' => $owner]), 'La préférence désactivée doit empêcher la création de la notification.');
    }

    public function testPreferencesPageSavesChoicesAndKeepsSecurityForced(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->makeUser($em, 'user.prefsave@example.com', 'user-prefsave');
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notifications/preferences');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/notifications/preferences', [
            'in_app_communaute' => '1',
            // email_communaute volontairement absent => décoché
            'in_app_contact' => '1',
            'email_contact' => '1',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/notifications/preferences');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $communaute = $em->getRepository(NotificationPreference::class)->findOneBy(['user' => $user, 'category' => NotificationCategory::COMMUNAUTE]);
        self::assertNotNull($communaute);
        self::assertTrue($communaute->isInAppEnabled());
        self::assertFalse($communaute->isEmailEnabled());

        // La catégorie sécurité n'est jamais persistée avec une valeur
        // désactivée, même si elle n'apparaît pas dans le formulaire.
        self::assertNull($em->getRepository(NotificationPreference::class)->findOneBy(['user' => $user, 'category' => NotificationCategory::SECURITE]));
    }

    public function testNotificationsArePaginated(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->makeUser($em, 'user.page@example.com', 'user-page');
        for ($i = 1; $i <= 25; ++$i) {
            $this->makeNotification($em, $user, 'Notification '.$i);
        }
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('article.m-carte'));

        $client->request('GET', '/notifications?page=2');
        self::assertResponseIsSuccessful();
    }
}
