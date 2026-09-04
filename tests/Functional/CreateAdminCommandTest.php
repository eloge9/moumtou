<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Bootstrap du premier administrateur (livraison client) : aucun compte
 * admin par défaut n'existe dans le dépôt — la personne qui installe
 * MOUMTOU choisit elle-même ses identifiants via `app:create-admin`.
 */
class CreateAdminCommandTest extends FunctionalTestCase
{
    private function makeCommandTester(): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:create-admin');

        return new CommandTester($command);
    }

    public function testCreatesTheFirstAdminWithChosenCredentials(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $tester = $this->makeCommandTester();
        $tester->setInputs(['Ada', 'Lovelace', 'ada@example.com', '', 'MotDePasse123', 'MotDePasse123']);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('créé avec succès', $tester->getDisplay());
        self::assertStringNotContainsString('MotDePasse123', $tester->getDisplay(), 'Le mot de passe ne doit jamais apparaître dans la sortie de la commande.');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'ada@example.com']);
        self::assertNotNull($admin);
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertContains('ROLE_TALENT', $admin->getRoles());
        self::assertSame(UserStatus::ACTIF, $admin->getStatus());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($admin, 'MotDePasse123'));
    }

    public function testRefusesToCreateASecondAdminWithoutForce(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $existing = $this->makeExistingAdmin($em, 'deja.admin@example.com');
        $em->flush();

        $tester = $this->makeCommandTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('existe déjà', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(User::class)->findAll());
    }

    public function testForceOptionAllowsCreatingAnAdditionalAdmin(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $this->makeExistingAdmin($em, 'premier.admin@example.com');
        $em->flush();

        $tester = $this->makeCommandTester();
        $tester->setInputs(['Second', 'Admin', 'second.admin@example.com', '', 'MotDePasse123', 'MotDePasse123']);
        $tester->execute(['--force' => true]);

        self::assertSame(0, $tester->getStatusCode());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(2, $em->getRepository(User::class)->findAll());
    }

    public function testRejectsMismatchedPasswordConfirmation(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $tester = $this->makeCommandTester();
        $tester->setInputs(['Ada', 'Lovelace', 'ada2@example.com', '', 'MotDePasse123', 'AutreChose456']);
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('ne correspondent pas', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(User::class)->findAll());
    }

    public function testRejectsAnEmailAlreadyUsedByAnotherAccountAndLetsRetry(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $existing = (new User())
            ->setEmail('pris@example.com')->setFirstName('Existe')->setLastName('Deja')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)
            ->setSlug('existe-deja')->setEmailVerified(true);
        $existing->setPassword($hasher->hashPassword($existing, 'MotDePasse123'));
        $em->persist($existing);
        $em->flush();

        $tester = $this->makeCommandTester();
        // Le premier e-mail est déjà pris : la question redemande une saisie.
        $tester->setInputs(['Ada', 'Lovelace', 'pris@example.com', 'libre@example.com', '', 'MotDePasse123', 'MotDePasse123']);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'libre@example.com']);
        self::assertNotNull($admin);
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
    }

    private function makeExistingAdmin(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = (new User())
            ->setEmail($email)->setFirstName('Admin')->setLastName('Existant')
            ->setPhone('+22890000000')->setRoles(['ROLE_ADMIN', 'ROLE_TALENT'])->setStatus(UserStatus::ACTIF)
            ->setSlug('admin-existant-'.substr(md5($email), 0, 6))->setEmailVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, 'MotDePasse123'));
        $em->persist($admin);

        return $admin;
    }
}
