<?php

namespace App\Tests\Functional;

use App\Entity\ContactRequest;
use App\Entity\Project;
use App\Entity\RecruiterFavorite;
use App\Entity\User;
use App\Enum\ContactRequestStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 7 : espace recruteur & mise en
 * relation (profil recruteur, favoris, demandes de contact, confidentialité,
 * permissions).
 */
class RecruiterTest extends FunctionalTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT'], array $overrides = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)->setFirstName($overrides['firstName'] ?? 'Test')->setLastName($overrides['lastName'] ?? ucfirst($slug))
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)->setEmailVerified(true);
        if (isset($overrides['whatsapp'])) {
            $user->setWhatsapp($overrides['whatsapp'])->setWhatsappEnabled($overrides['whatsappEnabled'] ?? true);
        }
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function makePublicProject(EntityManagerInterface $em, User $owner, string $slug, ProjectStatus $status = ProjectStatus::PUBLIE): Project
    {
        $project = new Project();
        $project->setName('Projet '.$slug);
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus($status);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);

        return $project;
    }

    public function testUserCanBecomeRecruiterWithoutCreatingASecondAccount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.become@example.com', 'talent-become');
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/recruteur/profil');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="recruiter_profile[_token]"]')->attr('value');

        $client->request('POST', '/recruteur/profil', [
            'recruiter_profile' => [
                'organizationName' => 'Acme Corp',
                'sector' => 'Informatique',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/recruteur/tableau-de-bord');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        self::assertContains('ROLE_RECRUITER', $refreshed->getRoles());
        self::assertContains('ROLE_TALENT', $refreshed->getRoles(), 'Le rôle existant ne doit pas être perdu (pas de second compte).');
        self::assertSame('Acme Corp', $refreshed->getRecruiterProfile()->getOrganizationName());

        // La session en cours (sans reconnexion) doit immédiatement donner
        // accès à l'espace recruteur : Security::login() réauthentifie le
        // jeton dans le contrôleur au moment de l'ajout du rôle.
        $client->request('GET', '/recruteur/tableau-de-bord');
        self::assertResponseIsSuccessful();
    }

    public function testNonRecruiterCannotAccessRecruiterDashboard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.noaccess@example.com', 'talent-noaccess');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/recruteur/tableau-de-bord');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotSendContactRequestOrFavorite(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.anon@example.com', 'talent-anon');
        $this->makePublicProject($em, $talent, 'projet-anon');
        $em->flush();

        $client->request('POST', '/recruteur/favoris/'.$talent->getId().'/ajouter', ['_csrf_token' => 'x']);
        self::assertResponseRedirects('/connexion');

        $client->request('POST', '/recruteur/demandes/'.$talent->getId().'/envoyer', ['message' => 'Bonjour', '_csrf_token' => 'x']);
        self::assertResponseRedirects('/connexion');
    }

    public function testRecruiterCanAddAndRemoveFavoriteWithoutDuplicate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.fav@example.com', 'talent-fav');
        $this->makePublicProject($em, $talent, 'projet-fav');
        $recruiter = $this->makeUser($em, 'recruiter.fav@example.com', 'recruiter-fav', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/profils/talent-fav');
        $token = $crawler->filter('form[action="/recruteur/favoris/'.$talent->getId().'/ajouter"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/recruteur/favoris/'.$talent->getId().'/ajouter', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/profils/talent-fav');
        // Second ajout : ne doit pas créer de doublon (contrainte unique recruiter+talent).
        $client->request('POST', '/recruteur/favoris/'.$talent->getId().'/ajouter', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(RecruiterFavorite::class)->findBy(['recruiter' => $recruiter, 'talent' => $talent]));

        $client->request('GET', '/recruteur/favoris');
        self::assertSelectorTextContains('body', 'Talent-fav');

        $crawler = $client->request('GET', '/profils/talent-fav');
        $removeToken = $crawler->filter('form[action="/recruteur/favoris/'.$talent->getId().'/retirer"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/recruteur/favoris/'.$talent->getId().'/retirer', ['_csrf_token' => $removeToken]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(RecruiterFavorite::class)->findBy(['recruiter' => $recruiter, 'talent' => $talent]));
    }

    public function testFullContactRequestFlowAcceptedByTalent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.contact@example.com', 'talent-contact');
        $this->makePublicProject($em, $talent, 'projet-contact');
        $recruiter = $this->makeUser($em, 'recruiter.contact@example.com', 'recruiter-contact', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/profils/talent-contact');
        $token = $crawler->filter('form[action="/recruteur/demandes/'.$talent->getId().'/envoyer"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/recruteur/demandes/'.$talent->getId().'/envoyer', [
            'message' => 'Bonjour, votre profil nous intéresse.',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/profils/talent-contact');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $contactRequest = $em->getRepository(ContactRequest::class)->findOneBy(['recruiter' => $recruiter, 'talent' => $talent]);
        self::assertNotNull($contactRequest);
        self::assertSame(ContactRequestStatus::PENDING, $contactRequest->getStatus());
        $requestId = $contactRequest->getId();

        // Doublon : impossible d'envoyer une seconde demande tant que la première est en attente.
        $client->request('POST', '/recruteur/demandes/'.$talent->getId().'/envoyer', ['message' => 'Encore ?', '_csrf_token' => $token]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(ContactRequest::class)->findBy(['recruiter' => $recruiter, 'talent' => $talent]));

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-espace-talent/demandes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'votre profil nous intéresse');
        $acceptToken = $crawler->filter('form[action="/mon-espace-talent/demandes/'.$requestId.'/accepter"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/mon-espace-talent/demandes/'.$requestId.'/accepter', ['_csrf_token' => $acceptToken]);
        self::assertResponseRedirects('/mon-espace-talent/demandes');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(ContactRequest::class)->find($requestId);
        self::assertSame(ContactRequestStatus::ACCEPTED, $refreshed->getStatus());
        self::assertNotNull($refreshed->getRespondedAt());
    }

    public function testTalentCanRefuseAContactRequest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.refuse@example.com', 'talent-refuse');
        $recruiter = $this->makeUser($em, 'recruiter.refuse@example.com', 'recruiter-refuse', ['ROLE_RECRUITER']);

        $contactRequest = new ContactRequest();
        $contactRequest->setRecruiter($recruiter)->setTalent($talent)->setMessage('Bonjour');
        $em->persist($contactRequest);
        $em->flush();
        $requestId = $contactRequest->getId();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-espace-talent/demandes');
        $token = $crawler->filter('form[action="/mon-espace-talent/demandes/'.$requestId.'/refuser"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/mon-espace-talent/demandes/'.$requestId.'/refuser', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(ContactRequestStatus::REFUSED, $em->getRepository(ContactRequest::class)->find($requestId)->getStatus());
    }

    public function testRecruiterCanCancelAPendingRequest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.cancel@example.com', 'talent-cancel');
        $recruiter = $this->makeUser($em, 'recruiter.cancel@example.com', 'recruiter-cancel', ['ROLE_RECRUITER']);

        $contactRequest = new ContactRequest();
        $contactRequest->setRecruiter($recruiter)->setTalent($talent)->setMessage('Bonjour');
        $em->persist($contactRequest);
        $em->flush();
        $requestId = $contactRequest->getId();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/recruteur/demandes');
        $token = $crawler->filter('form[action="/recruteur/demandes/'.$requestId.'/annuler"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/recruteur/demandes/'.$requestId.'/annuler', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(ContactRequestStatus::CANCELLED, $em->getRepository(ContactRequest::class)->find($requestId)->getStatus());
    }

    public function testOtherRecruiterCannotCancelSomeoneElsesRequest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.idor@example.com', 'talent-idor');
        $recruiterA = $this->makeUser($em, 'recruiter.idorA@example.com', 'recruiter-idorA', ['ROLE_RECRUITER']);
        $recruiterB = $this->makeUser($em, 'recruiter.idorB@example.com', 'recruiter-idorB', ['ROLE_RECRUITER']);

        $contactRequest = new ContactRequest();
        $contactRequest->setRecruiter($recruiterA)->setTalent($talent)->setMessage('Bonjour');
        $em->persist($contactRequest);
        $em->flush();
        $requestId = $contactRequest->getId();

        $client->loginUser($recruiterB);
        $client->request('POST', '/recruteur/demandes/'.$requestId.'/annuler', ['_csrf_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(ContactRequestStatus::PENDING, $em->getRepository(ContactRequest::class)->find($requestId)->getStatus());
    }

    public function testRecruiterCannotContactThemselves(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        // Un même compte peut cumuler ROLE_TALENT et ROLE_RECRUITER.
        $user = $this->makeUser($em, 'both.roles@example.com', 'both-roles', ['ROLE_TALENT', 'ROLE_RECRUITER']);
        $this->makePublicProject($em, $user, 'projet-both-roles');
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profils/both-roles');
        // Le propriétaire consultant son propre profil ne voit pas les actions recruteur.
        self::assertCount(0, $crawler->filter('form[action="/recruteur/demandes/'.$user->getId().'/envoyer"]'));

        // La règle "auto-contact interdit" est vérifiée avant le jeton CSRF
        // (aucun formulaire ne cible jamais son propre compte), donc un
        // jeton bidon suffit à prouver que c'est bien elle qui bloque.
        $client->request('POST', '/recruteur/demandes/'.$user->getId().'/envoyer', ['message' => 'Bonjour', '_csrf_token' => 'peu-importe']);
        self::assertResponseRedirects('/profils/both-roles');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(ContactRequest::class)->findBy(['recruiter' => $user, 'talent' => $user]));
    }

    public function testWhatsAppButtonHiddenWhenTalentDisablesIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talentEnabled = $this->makeUser($em, 'talent.wa1@example.com', 'talent-wa1', ['ROLE_TALENT'], ['whatsapp' => '22890001111', 'whatsappEnabled' => true]);
        $this->makePublicProject($em, $talentEnabled, 'projet-wa1');
        $talentDisabled = $this->makeUser($em, 'talent.wa2@example.com', 'talent-wa2', ['ROLE_TALENT'], ['whatsapp' => '22890002222', 'whatsappEnabled' => false]);
        $this->makePublicProject($em, $talentDisabled, 'projet-wa2');
        $recruiter = $this->makeUser($em, 'recruiter.wa@example.com', 'recruiter-wa', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/profils/talent-wa1');
        self::assertStringContainsString('wa.me/22890001111', $crawler->filter('body')->html());

        $crawler = $client->request('GET', '/profils/talent-wa2');
        self::assertStringNotContainsString('22890002222', $crawler->filter('body')->html());
    }

    public function testAdminCanFilterUsersByRecruiterRoleForModeration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $recruiter = $this->makeUser($em, 'recruiter.mod@example.com', 'recruiter-mod', ['ROLE_RECRUITER']);
        $admin = $this->makeUser($em, 'admin.mod@example.com', 'admin-mod', ['ROLE_ADMIN']);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs?role=ROLE_RECRUITER');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Recruiter-mod');
    }

    public function testTalentViewIsLoggedAndCountedOnRecruiterDashboard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.view@example.com', 'talent-view');
        $this->makePublicProject($em, $talent, 'projet-view');
        $recruiter = $this->makeUser($em, 'recruiter.view@example.com', 'recruiter-view', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $client->request('GET', '/profils/talent-view');
        $client->request('GET', '/profils/talent-view');

        $crawler = $client->request('GET', '/recruteur/tableau-de-bord');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Talent-view');
    }

    public function testPendingContactRequestsBadgeAppearsForTalentAndClearsAfterDecision(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.badge@example.com', 'talent-badge');
        $recruiter = $this->makeUser($em, 'recruiter.badge@example.com', 'recruiter-badge', ['ROLE_RECRUITER']);

        $contactRequest = new ContactRequest();
        $contactRequest->setRecruiter($recruiter)->setTalent($talent)->setMessage('Bonjour');
        $em->persist($contactRequest);
        $em->flush();
        $requestId = $contactRequest->getId();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/');
        self::assertSelectorTextContains('.m-menu__item[href="/mon-espace-talent/demandes"]', '1');

        $tokenCrawler = $client->request('GET', '/mon-espace-talent/demandes');
        $acceptToken = $tokenCrawler->filter('form[action="/mon-espace-talent/demandes/'.$requestId.'/accepter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/mon-espace-talent/demandes/'.$requestId.'/accepter', ['_csrf_token' => $acceptToken]);

        $crawler = $client->request('GET', '/');
        self::assertSelectorTextNotContains('.m-menu__item[href="/mon-espace-talent/demandes"]', '1');
    }
}
