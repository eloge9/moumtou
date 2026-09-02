<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Cahier des charges §5.1/§8/§48 : la structure OAuth doit exister et
 * dégrader proprement (message clair, pas de plantage) tant qu'aucune clé
 * d'API n'est configurée pour l'environnement de test.
 */
class OAuthTest extends FunctionalTestCase
{
    public function testUnconfiguredProviderRedirectsWithClearMessageInsteadOfCrashing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $client->request('GET', '/connexion/google');
        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'pas encore configurée');
    }

    public function testLoginAndRegisterPagesExposeOAuthButtons(): void
    {
        $client = static::createClient();

        $client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/connexion/google"]');
        self::assertSelectorExists('a[href="/connexion/facebook"]');
        self::assertSelectorExists('a[href="/connexion/linkedin"]');

        $client->request('GET', '/inscription');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/connexion/google"]');
    }

    public function testUnknownProviderIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion/twitter');
        self::assertResponseStatusCodeSame(404);
    }
}
