<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Un refus d'accès (403) ou une ressource introuvable (404) fait partie du
 * fonctionnement normal de l'application : l'utilisateur doit voir un
 * écran MOUMTOU clair, jamais la page de débogage technique de Symfony
 * (même en environnement dev).
 */
class ErrorPagesTest extends FunctionalTestCase
{
    public function testAccessDeniedShowsStyledErrorPageInsteadOfDebugTrace(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $talent = (new User())->setEmail('err403@example.com')->setFirstName('Err')->setLastName('Test')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('err403-test');
        $talent->setPassword($hasher->hashPassword($talent, 'MotDePasse123'));
        $em->persist($talent);
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/recruteur');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Accès refusé');
        self::assertStringNotContainsString('ExceptionListener', $client->getResponse()->getContent());
        self::assertStringNotContainsString('Stack trace', $client->getResponse()->getContent());
    }

    public function testMissingProjectShowsStyledNotFoundPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $client->request('GET', '/projets/ce-projet-n-existe-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'introuvable');
    }
}
