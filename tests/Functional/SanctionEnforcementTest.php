<?php

namespace App\Tests\Functional;

use App\Entity\Sanction;
use App\Entity\User;
use App\Enum\SanctionType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §32/§35 : un compte suspendu ou banni ne doit plus
 * pouvoir réellement utiliser la plateforme (pas seulement afficher un badge).
 */
class SanctionEnforcementTest extends FunctionalTestCase
{
    public function testBannedUserCannotLogIn(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.sanction@example.com', ['ROLE_ADMIN'], 'admin-sanction');
        $banned = $this->makeUser($em, 'banni@example.com', ['ROLE_TALENT'], 'utilisateur-banni');
        $banned->setStatus(UserStatus::BANNI);
        $em->flush();

        $sanction = new Sanction();
        $sanction->setUser($banned);
        $sanction->setAdmin($admin);
        $sanction->setType(SanctionType::BANNISSEMENT);
        $sanction->setReason('Contenu frauduleux');
        $em->persist($sanction);
        $em->flush();

        $crawler = $client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'banni@example.com',
            '_password' => 'MotDePasse123',
        ]);
        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('.m-avis', 'banni');
    }

    public function testSuspendedUserCannotLogInUntilSuspensionExpires(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.sanction2@example.com', ['ROLE_ADMIN'], 'admin-sanction2');
        $suspended = $this->makeUser($em, 'suspendu@example.com', ['ROLE_TALENT'], 'utilisateur-suspendu');
        $suspended->setStatus(UserStatus::SUSPENDU);
        $em->flush();

        $sanction = new Sanction();
        $sanction->setUser($suspended);
        $sanction->setAdmin($admin);
        $sanction->setType(SanctionType::SUSPENSION);
        $sanction->setReason('Commentaire déplacé');
        $sanction->setEndAt(new \DateTimeImmutable('-1 minute')); // déjà expirée
        $em->persist($sanction);
        $em->flush();

        $crawler = $client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'suspendu@example.com',
            '_password' => 'MotDePasse123',
        ]);
        $client->submit($form);

        // La suspension étant expirée, la connexion doit réussir et réactiver le compte.
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($suspended->getId());
        self::assertSame(UserStatus::ACTIF, $refreshed->getStatus());
    }

    public function testActiveSessionIsClosedAssoonAsAccountIsBannedMidSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'seance@example.com', ['ROLE_TALENT'], 'seance-active');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // Un admin bannit le compte pendant que sa session est encore active.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        $refreshed->setStatus(UserStatus::BANNI);
        $em->flush();

        $client->request('GET', '/publier');
        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'banni');

        // La session doit être réellement close : plus d'accès aux pages protégées.
        $client->request('GET', '/publier');
        self::assertResponseRedirects('/connexion');
    }

    private function makeUser(EntityManagerInterface $em, string $email, array $roles, string $slug): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName(ucfirst($slug))
            ->setPhone('+22890000000')
            ->setRoles($roles)
            ->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)
            ->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }
}
