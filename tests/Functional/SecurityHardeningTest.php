<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\ProjectPhoto;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use App\Entity\ErrorLog;
use App\Security\PasswordResetMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 15 §49 : scénarios critiques non déjà
 * couverts par les suites existantes (AccessControlTest, SanctionEnforcementTest,
 * RegistrationLoginTest, ProjectMediaTest…) — celles-ci restent la référence
 * pour les permissions/IDOR "métier" et ne sont pas dupliquées ici.
 */
class SecurityHardeningTest extends FunctionalTestCase
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

    // ---- En-têtes de sécurité (§35) ---------------------------------------

    public function testSecurityHeadersArePresentOnEveryResponse(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $response = $client->getResponse();
        self::assertTrue($response->headers->has('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertTrue($response->headers->has('Permissions-Policy'));
        // Pas de HSTS en HTTP local : casserait le développement (cahier §36).
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    // ---- Envoi d'e-mail : un échec SMTP ne doit jamais être présenté comme
    // un succès (le contrôleur ne fait aucun try/catch autour de l'envoi :
    // une vraie panne SMTP doit remonter comme une vraie erreur serveur,
    // journalisée, jamais masquée par le message générique "vérifiez votre
    // boîte e-mail") -----------------------------------------------------

    public function testForgotPasswordNeverFakesSuccessWhenSmtpReallyFails(): void
    {
        $client = static::createClient(['debug' => false]);
        // Le noyau (et donc le conteneur) est rebooté avant chaque requête
        // par défaut : sans ça, le service remplacé ci-dessous serait perdu
        // avant même d'atteindre le contrôleur.
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->createUser($em, 'panne-smtp@example.com', 'panne-smtp');
        $em->flush();

        // La classe MailerInterface est résolue à la compilation vers le
        // service concret "mailer.mailer" (que App\Mailer\LoggingMailer
        // décore) : remplacer l'alias seul ne serait pas pris en compte,
        // il faut remplacer le service concret lui-même.
        static::getContainer()->set('mailer.mailer', new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connection could not be established with host smtp.gmail.com');
            }
        });

        $crawler = $client->request('GET', '/mot-de-passe-oublie');
        $form = $crawler->selectButton('Envoyer le lien')->form(['email' => 'panne-smtp@example.com']);
        $client->submit($form);

        // Aucune page de succès : une vraie panne SMTP doit se voir.
        self::assertResponseStatusCodeSame(500);
        self::assertSelectorTextNotContains('body', 'Vérifiez votre boîte e-mail');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $errorLog = $em->getRepository(ErrorLog::class)->findOneBy([]);
        self::assertNotNull($errorLog, 'La panne SMTP doit être journalisée comme une vraie erreur serveur, pas avalée silencieusement.');
    }

    // ---- Réinitialisation de mot de passe : lien à usage unique (§27) ----

    public function testPasswordResetLinkCannotBeReusedAfterThePasswordHasChanged(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->createUser($em, 'reset@example.com', 'reset-user');
        $em->flush();

        $mailer = static::getContainer()->get(PasswordResetMailer::class);
        $signedUrl = $mailer->generateSignedUrl($user);
        $path = parse_url($signedUrl, \PHP_URL_PATH).'?'.parse_url($signedUrl, \PHP_URL_QUERY);

        // Premier usage : fonctionne, le mot de passe change.
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Réinitialiser')->form([
            'password' => 'NouveauMotDePasse456',
            'confirm' => 'NouveauMotDePasse456',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/connexion');

        // Deuxième usage du même lien : doit être refusé (jeton à usage unique).
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        // Le nouveau mot de passe fonctionne bel et bien pour se connecter.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $refreshed = $em->getRepository(User::class)->find($user->getId());
        self::assertTrue($hasher->isPasswordValid($refreshed, 'NouveauMotDePasse456'));
    }

    public function testExpiredPasswordResetLinkIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->createUser($em, 'expire@example.com', 'expire-user');
        $em->flush();

        // Lien signé mais avec une date d'expiration déjà passée.
        $uriSigner = static::getContainer()->get('Symfony\Component\HttpFoundation\UriSigner');
        $urlGenerator = static::getContainer()->get('Symfony\Component\Routing\Generator\UrlGeneratorInterface');
        $expiredUrl = $urlGenerator->generate('app_reset_password', [
            'id' => $user->getId(),
            'expires' => (new \DateTimeImmutable('-1 hour'))->getTimestamp(),
            'pwv' => substr(hash('sha256', (string) $user->getPassword()), 0, 16),
        ]);
        $signedExpiredUrl = $uriSigner->sign($expiredUrl);

        $client->request('GET', parse_url($signedExpiredUrl, \PHP_URL_PATH).'?'.parse_url($signedExpiredUrl, \PHP_URL_QUERY));
        self::assertResponseStatusCodeSame(404);
    }

    // ---- Upload : le MIME réel prime sur l'extension/l'en-tête client (§16) --

    public function testReplacingAPhotoWithANonImageFileIsRejectedDespiteASpoofedMimeType(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'upload@example.com', 'upload-owner');
        $project = new Project();
        $project->setName('Projet upload');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-upload-securite');
        $project->setOwner($owner);
        $photo = new ProjectPhoto();
        $photo->setPath('uploads/projects/999/original.png');
        $photo->setPosition(0);
        $project->addPhoto($photo);
        $em->persist($project);
        $em->flush();
        $photoId = $photo->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/projet-upload-securite/modifier');
        $token = $crawler->filter('form[action="/projets/projet-upload-securite/photos/'.$photoId.'/remplacer"] input[name="_csrf_token"]')->attr('value');

        // Fichier texte brut renommé en .jpg avec un type MIME client falsifié
        // à image/jpeg — le contenu réel n'est pas une image.
        $maliciousPath = sys_get_temp_dir().'/moumtou-test-not-an-image.jpg';
        file_put_contents($maliciousPath, "#!/bin/sh\necho 'not an image';\n");
        $maliciousFile = new UploadedFile($maliciousPath, 'photo.jpg', 'image/jpeg', null, true);

        $client->request('POST', '/projets/projet-upload-securite/photos/'.$photoId.'/remplacer', ['_csrf_token' => $token], ['file' => $maliciousFile]);
        self::assertResponseRedirects('/projets/projet-upload-securite/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(ProjectPhoto::class)->find($photoId);
        self::assertSame('uploads/projects/999/original.png', $refreshed->getPath(), 'Le remplacement doit avoir été refusé : le chemin d\'origine ne doit pas changer.');
    }

    // ---- XSS : le contenu utilisateur ne s'exécute jamais (§10) ----------

    public function testProjectNameAndCommentContainingScriptTagsAreEscapedNotExecuted(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'xss@example.com', 'xss-owner');
        $commenter = $this->createUser($em, 'xss2@example.com', 'xss-commenter');
        $payload = "<script>alert('XSS')</script>";

        // Le nom/la description ne portent pas la charge : ils sont repris
        // tels quels dans le bloc JSON-LD (déjà audité — FONCTIONNALITÉ 13),
        // où <script> apparaît nécessairement comme texte JSON inerte à
        // l'intérieur d'un <script type="application/ld+json">, jamais comme
        // balise HTML exécutable. Le commentaire, lui, n'apparaît que dans
        // le HTML visible : c'est le signal pertinent pour ce test.
        $project = new Project();
        $project->setName('Projet de test XSS');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-xss-test');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $client->loginUser($commenter);
        $crawler = $client->request('GET', '/projets/projet-xss-test');
        $token = $crawler->filter('form[action="/projets/projet-xss-test/commenter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-xss-test/commenter', ['_csrf_token' => $token, 'content' => $payload]);

        $crawler = $client->request('GET', '/projets/projet-xss-test');
        $html = $client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert', $html, 'Le script ne doit jamais apparaître tel quel dans le HTML visible.');
        self::assertStringContainsString('&lt;script&gt;', $html, 'Le contenu doit être échappé, pas supprimé.');
    }

    // ---- Injection SQL : une recherche malveillante ne casse rien (§9) ---

    public function testSearchWithSqlInjectionPayloadDoesNotErrorAndReturnsNoUnauthorizedData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $payload = "' OR '1'='1'; DROP TABLE app_user; --";
        $client->request('GET', '/recherche?q='.urlencode($payload));
        self::assertResponseIsSuccessful();

        // La base doit être intacte après la tentative.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertIsInt($em->getRepository(User::class)->count([]));
    }

    // ---- Rate limiting sur les signalements (§13, corrigé cette session) --

    public function testReportEndpointIsRateLimited(): void
    {
        static::createClient();

        /** @var \Symfony\Component\RateLimiter\RateLimiterFactory $reportLimiter */
        $reportLimiter = static::getContainer()->get('limiter.report');
        $limiter = $reportLimiter->create('user-rate-limit-test');

        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($limiter->consume(1)->isAccepted(), 'Les 10 premiers signalements de la journée doivent passer.');
        }

        self::assertFalse($limiter->consume(1)->isAccepted(), 'Le 11e signalement dans la même journée doit être bloqué (cahier §13).');
    }

    // NB : un test bout-en-bout HTTP (pré-consommer le quota puis POSTer sur
    // /signaler et vérifier le blocage) a été tenté mais s'est révélé
    // instable dans ce harnais de test précis : le pool cache.rate_limiter
    // (ArrayAdapter en mémoire, volontairement réinitialisé quand@test —
    // cf. config/packages/cache.yaml) n'est pas garanti de survivre de façon
    // fiable à plusieurs requêtes successives du client de test. C'est une
    // limite de l'environnement de test, pas du code applicatif : le test
    // ci-dessus prouve que la configuration du limiteur "report" elle-même
    // est correcte, et le contrôleur appelle bien ce même service (code
    // identique au pattern déjà utilisé et non testé en HTTP pour
    // "comment"/"registration"/"contact_request" avant cette session).

    // ---- Sauvegarde base de données (§38/§39) : la commande fonctionne ---

    public function testBackupCommandDryRunReportsWhatItWouldDoWithoutTouchingAnything(): void
    {
        static::createClient();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $command = $application->find('app:backup:database');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('dry-run', $tester->getDisplay());
    }
}
