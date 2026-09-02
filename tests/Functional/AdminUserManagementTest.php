<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §4.6/§29 : l'administrateur doit pouvoir gérer
 * directement les utilisateurs (suspendre, bannir, réactiver), sans passer
 * uniquement par le circuit de signalement.
 */
class AdminUserManagementTest extends FunctionalTestCase
{
    public function testAdminCanListSuspendAndReactivateAUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = (new User())->setEmail('admin.users@example.com')->setFirstName('A')->setLastName('Dmin')
            ->setPhone('+22890000000')->setRoles(['ROLE_ADMIN'])->setStatus(UserStatus::ACTIF)->setSlug('admin-users-test');
        $admin->setPassword($hasher->hashPassword($admin, 'MotDePasse123'));
        $em->persist($admin);

        $target = (new User())->setEmail('cible@example.com')->setFirstName('Cible')->setLastName('Test')
            ->setPhone('+22890000001')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('cible-test');
        $target->setPassword($hasher->hashPassword($target, 'MotDePasse123'));
        $em->persist($target);
        $em->flush();
        $targetId = $target->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'cible@example.com');

        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$targetId.'/sanctionner"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/utilisateurs/'.$targetId.'/sanctionner', [
            'action' => 'suspendre_7',
            'reason' => 'Comportement suspect signalé hors plateforme',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/utilisateurs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($targetId);
        self::assertSame(UserStatus::SUSPENDU, $refreshed->getStatus());

        // Réactivation manuelle.
        $crawler = $client->request('GET', '/admin/utilisateurs');
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$targetId.'/sanctionner"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/utilisateurs/'.$targetId.'/sanctionner', [
            'action' => 'reactiver',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/utilisateurs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $reactivated = $em->getRepository(User::class)->find($targetId);
        self::assertSame(UserStatus::ACTIF, $reactivated->getStatus());
    }

    public function testAdminCannotSanctionOwnAccount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = (new User())->setEmail('admin.self@example.com')->setFirstName('A')->setLastName('Dmin')
            ->setPhone('+22890000000')->setRoles(['ROLE_ADMIN'])->setStatus(UserStatus::ACTIF)->setSlug('admin-self-test');
        $admin->setPassword($hasher->hashPassword($admin, 'MotDePasse123'));
        $em->persist($admin);
        $em->flush();
        $adminId = $admin->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs');
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$adminId.'/sanctionner"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/utilisateurs/'.$adminId.'/sanctionner', [
            'action' => 'bannir',
            'reason' => 'Test',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/utilisateurs');
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'propre compte');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($adminId);
        self::assertSame(UserStatus::ACTIF, $refreshed->getStatus());
    }
}
