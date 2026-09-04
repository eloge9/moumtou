<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class RegistrationLoginTest extends FunctionalTestCase
{
    /**
     * Règles 1/2/3 : TALENT toujours attribué, connexion automatique après
     * l'inscription, redirection vers la complétion du profil — sans
     * attendre la vérification de l'e-mail (qui reste envoyée, mais ne
     * bloque plus rien).
     */
    public function testRegistrationLogsInAutomaticallyAndGrantsTalentRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $crawler = $client->request('GET', '/inscription');
        $form = $crawler->selectButton('Créer mon compte')->form([
            'registration_form[firstName]' => 'Ama',
            'registration_form[lastName]' => 'Koffi',
            'registration_form[phone]' => '+22890000000',
            'registration_form[email]' => 'ama.koffi@example.com',
            'registration_form[plainPassword][first]' => 'MotDePasse123',
            'registration_form[plainPassword][second]' => 'MotDePasse123',
        ]);
        $client->submit($form);

        // Connexion automatique + redirection vers la page de bienvenue (§5).
        self::assertResponseRedirects('/bienvenue');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bienvenue sur MOUMTOU');
        self::assertSelectorExists('a[href="'.self::getContainer()->get('router')->generate('app_profile_edit').'"]');

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'ama.koffi@example.com']);
        self::assertNotNull($user, 'L\'utilisateur doit être persisté après inscription.');
        self::assertSame(['ROLE_TALENT', 'ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isEmailVerified(), 'L\'e-mail ne doit pas être vérifié avant de cliquer sur le lien (mais ne bloque plus la connexion).');

        // La session est bien authentifiée dès la redirection : une page
        // protégée par ROLE_USER doit être immédiatement accessible.
        $client->request('GET', '/mon-profil/modifier');
        self::assertResponseIsSuccessful();
    }

    public function testEmailVerificationLinkStillWorksAfterAutomaticLogin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $crawler = $client->request('GET', '/inscription');
        $form = $crawler->selectButton('Créer mon compte')->form([
            'registration_form[firstName]' => 'Ama',
            'registration_form[lastName]' => 'Koffi',
            'registration_form[phone]' => '+22890000000',
            'registration_form[email]' => 'ama.verif@example.com',
            'registration_form[plainPassword][first]' => 'MotDePasse123',
            'registration_form[plainPassword][second]' => 'MotDePasse123',
        ]);
        $client->submit($form);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'ama.verif@example.com']);
        $emailVerifier = static::getContainer()->get(\App\Security\EmailVerifier::class);
        $signedUrl = $emailVerifier->generateSignedUrl($user);
        $client->request('GET', $signedUrl);
        self::assertResponseRedirects('/connexion');

        $em->refresh($user);
        self::assertTrue($user->isEmailVerified());
    }

    /**
     * Règle 9 : le choix « Étudiant » à l'inscription ne rend PAS le rôle
     * actif immédiatement — il redirige vers le formulaire dédié, qui seul
     * active le rôle une fois complété.
     */
    public function testChoosingStudentAtRegistrationRedirectsToDedicatedFormBeforeActivatingRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $crawler = $client->request('GET', '/inscription');
        $form = $crawler->selectButton('Créer mon compte')->form([
            'registration_form[accountType]' => 'student',
            'registration_form[firstName]' => 'Kodjo',
            'registration_form[lastName]' => 'Mensah',
            'registration_form[phone]' => '+22890000001',
            'registration_form[email]' => 'kodjo.student@example.com',
            'registration_form[plainPassword][first]' => 'MotDePasse123',
            'registration_form[plainPassword][second]' => 'MotDePasse123',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/bienvenue?role=student');
        $client->followRedirect();
        self::assertSelectorExists('a[href*="devenir-etudiant"]');

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'kodjo.student@example.com']);
        self::assertSame(['ROLE_TALENT', 'ROLE_USER'], $user->getRoles(), 'ROLE_STUDENT ne doit pas être actif avant complétion du formulaire dédié.');
    }

    public function testLoginWithWrongPasswordShowsError(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $passwordHasher = static::getContainer()->get('security.user_password_hasher');
        $user = (new User())
            ->setEmail('login.test@example.com')
            ->setFirstName('Login')
            ->setLastName('Test')
            ->setPhone('+22890000003')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, 'BonMotDePasse1'));
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'login.test@example.com',
            '_password' => 'MauvaisMotDePasse',
        ]);
        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('.m-avis', 'Identifiants invalides');
    }

    /**
     * Test déconnexion/reconnexion (§38) : les rôles et le profil sont
     * conservés d'une session à l'autre.
     */
    public function testRolesAndProfileArePreservedAcrossLogoutAndLogin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $passwordHasher = static::getContainer()->get('security.user_password_hasher');
        $user = (new User())
            ->setEmail('persist.roles@example.com')
            ->setFirstName('Persist')
            ->setLastName('Roles')
            ->setPhone('+22890000004')
            ->setSlug('persist-roles')
            ->setStatus(\App\Enum\UserStatus::ACTIF)
            ->setRoles(['ROLE_TALENT', 'ROLE_TEACHER']);
        $user->setPassword($passwordHasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/deconnexion');

        $crawler = $client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'persist.roles@example.com',
            '_password' => 'MotDePasse123',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->findOneBy(['email' => 'persist.roles@example.com']);
        self::assertSame(['ROLE_TALENT', 'ROLE_TEACHER', 'ROLE_USER'], $refreshed->getRoles());
    }

    public function testRegistrationRejectsDuplicateEmail(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $existing = (new User())
            ->setEmail('duplicate@example.com')
            ->setFirstName('Test')
            ->setLastName('Existant')
            ->setPhone('+22890000001')
            ->setPassword('irrelevant-hash')
            ->setRoles(['ROLE_USER']);
        $em->persist($existing);
        $em->flush();

        $crawler = $client->request('GET', '/inscription');
        $form = $crawler->selectButton('Créer mon compte')->form([
            'registration_form[firstName]' => 'Autre',
            'registration_form[lastName]' => 'Personne',
            'registration_form[phone]' => '+22890000002',
            'registration_form[email]' => 'duplicate@example.com',
            'registration_form[plainPassword][first]' => 'MotDePasse123',
            'registration_form[plainPassword][second]' => 'MotDePasse123',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un compte existe déjà avec cette adresse e-mail.');
    }

    /**
     * Sécurité (règle 8) : le champ `roles` n'étant mappé sur aucun
     * formulaire, un attaquant ne peut pas s'auto-attribuer ROLE_ADMIN en
     * ajoutant un champ supplémentaire à la requête d'inscription.
     */
    public function testExtraRolesFieldInRegistrationRequestHasNoEffect(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $crawler = $client->request('GET', '/inscription');
        $form = $crawler->selectButton('Créer mon compte')->form([
            'registration_form[firstName]' => 'Escalade',
            'registration_form[lastName]' => 'Privilege',
            'registration_form[phone]' => '+22890000009',
            'registration_form[email]' => 'escalade@example.com',
            'registration_form[plainPassword][first]' => 'MotDePasse123',
            'registration_form[plainPassword][second]' => 'MotDePasse123',
        ]);
        // Champ non déclaré par RegistrationFormType, injecté manuellement :
        // ni ce champ ni "accountType=admin" (inexistant côté enum) ne
        // doivent jamais pouvoir accorder ROLE_ADMIN (règle 8).
        $values = $form->getPhpValues();
        $values['registration_form']['roles'] = ['ROLE_ADMIN'];
        $client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());

        // Que Symfony ait ignoré le champ superflu ou rejeté la requête,
        // aucun utilisateur avec ROLE_ADMIN ne doit exister dans les deux cas.
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'escalade@example.com']);
        self::assertFalse(
            null !== $user && \in_array('ROLE_ADMIN', $user->getRoles(), true),
            'Un champ "roles" injecté manuellement ne doit jamais accorder ROLE_ADMIN.',
        );
    }
}
