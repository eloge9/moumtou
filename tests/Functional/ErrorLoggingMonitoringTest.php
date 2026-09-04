<?php

namespace App\Tests\Functional;

use App\Entity\ErrorLog;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use App\EventListener\RequestIdListener;
use App\Service\CriticalErrorAlerter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 18 : identifiant de corrélation,
 * journalisation technique des erreurs 5xx, alertes critiques anti-spam,
 * contrôle de santé, tableau de bord de monitoring (accès admin uniquement),
 * format d'erreur JSON uniforme.
 */
class ErrorLoggingMonitoringTest extends FunctionalTestCase
{
    private function createUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setPhone('+22890000000')
            ->setRoles($roles)
            ->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)
            ->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    // ---- Identifiant de corrélation (§5/§39) ------------------------------

    public function testEveryResponseCarriesARequestIdHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $requestId = $client->getResponse()->headers->get(RequestIdListener::HEADER);
        self::assertNotNull($requestId);
        self::assertMatchesRegularExpression('/^[0-9A-F]{16}$/', $requestId);
    }

    /**
     * Preuve de corrélation bout-en-bout (§39) : le même identifiant est
     * visible côté réponse (en-tête ET page affichée) et côté journal
     * persistant ({@see ErrorLog}) — un incident réel est donc traçable.
     */
    public function testRequestIdIsIdenticalAcrossHeaderPageAndPersistedErrorLog(): void
    {
        $client = static::createClient(['debug' => false]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $client->request('GET', '/_test/throw-error');

        $headerRequestId = $client->getResponse()->headers->get(RequestIdListener::HEADER);
        self::assertNotNull($headerRequestId);
        self::assertStringContainsString($headerRequestId, $client->getResponse()->getContent());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $errorLog = $em->getRepository(ErrorLog::class)->findOneBy([]);
        self::assertNotNull($errorLog);
        self::assertSame($headerRequestId, $errorLog->getRequestId());
    }

    // ---- Journalisation technique des erreurs serveur (§9/§25) -----------

    public function testOrdinaryServerErrorIsPersistedAsErrorLevelNotCritical(): void
    {
        $client = static::createClient(['debug' => false]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $client->request('GET', '/_test/throw-error');
        self::assertResponseStatusCodeSame(500);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $errorLog = $em->getRepository(ErrorLog::class)->findOneBy([]);
        self::assertNotNull($errorLog);
        self::assertSame('error', $errorLog->getLevel());
        self::assertSame(500, $errorLog->getStatusCode());
        self::assertSame('GET', $errorLog->getMethod());
        self::assertSame('/_test/throw-error', $errorLog->getPath());
        self::assertSame(\RuntimeException::class, $errorLog->getExceptionClass());
        self::assertSame('Erreur de test déclenchée volontairement.', $errorLog->getMessage());
    }

    public function testDatabaseLayerErrorIsClassifiedAsCritical(): void
    {
        $client = static::createClient(['debug' => false]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $client->request('GET', '/_test/throw-error?type=critical');
        self::assertResponseStatusCodeSame(500);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $errorLog = $em->getRepository(ErrorLog::class)->findOneBy([]);
        self::assertNotNull($errorLog);
        self::assertSame('critical', $errorLog->getLevel());
    }

    // ---- Alerte critique anti-spam (§33/§34) ------------------------------

    public function testRepeatedIdenticalCriticalErrorsTriggerOnlyOneAlertWithinTheCooldownWindow(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin-monitoring@example.com', 'admin-monitoring', ['ROLE_ADMIN']);
        $em->flush();

        $alerter = static::getContainer()->get(CriticalErrorAlerter::class);
        for ($i = 0; $i < 5; ++$i) {
            $alerter->alert(\RuntimeException::class, 'Panne simulée identique', '/route-en-panne', 'REQID0000000000');
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = $em->getRepository(Notification::class)->count([
            'recipient' => $admin,
            'type' => NotificationType::CRITICAL_ERROR,
        ]);
        self::assertSame(1, $count, 'Le regroupement anti-spam doit limiter 5 occurrences identiques à une seule alerte.');
    }

    // ---- Contrôle de santé (§22) -------------------------------------------

    public function testHealthEndpointReportsOkWhenEverythingWorks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('ok', $data['status']);
        self::assertSame('ok', $data['database']);
        self::assertSame('ok', $data['storage']);
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('dsn', $data);
    }

    public function testHealthEndpointIsPubliclyAccessibleWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
        self::assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    // ---- Format JSON uniforme des erreurs API (§4) -------------------------

    public function testApiRouteReturnsUniformJsonErrorFormatOnServerError(): void
    {
        $client = static::createClient(['debug' => false]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $client->request('GET', '/_test/throw-error', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(500);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
        self::assertSame('INTERNAL_ERROR', $data['error']['code']);
        self::assertArrayHasKey('request_id', $data);
        self::assertStringNotContainsString('RuntimeException', json_encode($data));
    }

    // ---- Tableau de bord "Monitoring" : accès admin uniquement (§25/§40) --

    public function testNonAdminCannotAccessMonitoringDashboard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'talent-monitoring@example.com', 'talent-monitoring');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/admin/monitoring');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotAccessMonitoringDashboard(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/monitoring');
        self::assertResponseRedirects();
    }

    public function testAdminSeesPersistedErrorInMonitoringDashboardWithoutStackTrace(): void
    {
        $client = static::createClient(['debug' => false]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin-view@example.com', 'admin-view', ['ROLE_ADMIN']);
        $em->flush();

        // Provoque une vraie erreur serveur à journaliser.
        $client->request('GET', '/_test/throw-error');
        self::assertResponseStatusCodeSame(500);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $errorLog = $em->getRepository(ErrorLog::class)->findOneBy([]);
        self::assertNotNull($errorLog);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/monitoring');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/_test/throw-error', $client->getResponse()->getContent());

        $client->request('GET', '/admin/monitoring/'.$errorLog->getId());
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('Erreur de test déclenchée volontairement.', $content);
        self::assertStringNotContainsString('#0 ', $content, 'Une trace technique (format "#0 fichier(ligne)") ne doit jamais apparaître.');
        self::assertStringNotContainsString('TestErrorController.php', $content);
    }
}
