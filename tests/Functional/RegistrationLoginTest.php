<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class RegistrationLoginTest extends FunctionalTestCase
{
    public function testRegistrationThenEmailVerificationThenLogin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        // 1. Inscription
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

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vérifiez votre boîte e-mail');

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'ama.koffi@example.com']);
        self::assertNotNull($user, 'L\'utilisateur doit être persisté après inscription.');
        self::assertFalse($user->isEmailVerified(), 'L\'e-mail ne doit pas être vérifié avant de cliquer sur le lien.');
        self::assertSame(['ROLE_TALENT', 'ROLE_USER'], $user->getRoles());

        // 2. Vérification de l'e-mail via l'URL signée
        $emailVerifier = static::getContainer()->get(\App\Security\EmailVerifier::class);
        $signedUrl = $emailVerifier->generateSignedUrl($user);
        $client->request('GET', $signedUrl);
        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'confirmée');

        $em->refresh($user);
        self::assertTrue($user->isEmailVerified());

        // 4. Connexion avec les identifiants créés
        $crawler = $client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'ama.koffi@example.com',
            '_password' => 'MotDePasse123',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/profils/ama-koffi');
        $client->followRedirect();
        self::assertSelectorTextContains('.m-header', 'Ama');
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
}
