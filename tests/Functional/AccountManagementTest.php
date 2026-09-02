<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §5.3 : changement de mot de passe et suppression de
 * compte pour un utilisateur déjà connecté.
 */
class AccountManagementTest extends FunctionalTestCase
{
    public function testUserCanChangeOwnPassword(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('changepw@example.com')
            ->setFirstName('Test')->setLastName('Password')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])
            ->setStatus(UserStatus::ACTIF)->setSlug('changepw')->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'AncienMotDePasse1'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $token = $crawler->filter('input[name="_csrf_token"]')->eq(0)->attr('value');

        $client->request('POST', '/mon-compte/mot-de-passe', [
            'current_password' => 'AncienMotDePasse1',
            'new_password' => 'NouveauMotDePasse1',
            'new_password_confirm' => 'NouveauMotDePasse1',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/mon-profil/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($user->getId());
        self::assertTrue($hasher->isPasswordValid($refreshed, 'NouveauMotDePasse1'));
    }

    public function testUserCanDeleteOwnAccountWithPasswordConfirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('deleteme@example.com')
            ->setFirstName('Test')->setLastName('Delete')
            ->setPhone('+22890000001')->setRoles(['ROLE_TALENT'])
            ->setStatus(UserStatus::ACTIF)->setSlug('deleteme')->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);
        $em->flush();
        $userId = $user->getId();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $token = $crawler->filter('input[name="_csrf_token"]')->last()->attr('value');

        $client->request('POST', '/mon-compte/supprimer', [
            'password' => 'MotDePasse123',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $deleted = $em->getRepository(User::class)->find($userId);
        self::assertSame(UserStatus::SUPPRIME, $deleted->getStatus());
        self::assertSame('Compte', $deleted->getFirstName());
        self::assertNull($deleted->getBio());

        // Le compte supprimé ne doit plus jamais pouvoir se reconnecter.
        $crawler = $client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $deleted->getEmail(),
            '_password' => 'MotDePasse123',
        ]);
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'supprimé');
    }
}
