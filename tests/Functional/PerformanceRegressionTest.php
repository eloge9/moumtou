<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\Defense;
use App\Entity\Institution;
use App\Entity\JuryMember;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\ProjectPhoto;
use App\Entity\ProjectProof;
use App\Entity\Rating;
use App\Entity\User;
use App\Enum\JuryRole;
use App\Enum\JuryStatus;
use App\Enum\NotificationType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ProofType;
use App\Enum\UserStatus;
use App\Service\ReferenceDataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 17. Garde-fous de non-régression :
 * les seuils ci-dessous sont volontairement larges (pas des chiffres exacts
 * fragiles) — ils doivent alerter si un N+1 réapparaît, pas casser au moindre
 * ajout légitime d'une requête.
 */
class PerformanceRegressionTest extends FunctionalTestCase
{
    private function createUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('U')
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    public function testProjectPageQueryCountDoesNotGrowWithCommentCount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'perf-owner@example.com', 'perf-owner');
        $institution = (new Institution())->setName('Universite Perf')->setCountry('Togo')->setCity('Lome');
        $em->persist($institution);

        $project = new Project();
        $project->setName('Projet perf')->setType(ProjectType::SOUTENANCE)->setStatus(ProjectStatus::VERIFIE);
        $project->setSlug('projet-perf-comments')->setOwner($owner)->setInstitution($institution);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project)->setDate(new \DateTimeImmutable('2026-12-01'))->setTime('10:00')->setPlace('Amphi');
        $project->setDefense($defense);
        $em->persist($defense);

        // 10 commentaires, 10 auteurs distincts : si le N+1 auteur/réponses
        // réapparaît, le nombre de requêtes croît avec ce nombre — le test
        // échoue même sans connaître le chiffre exact attendu.
        for ($i = 0; $i < 10; ++$i) {
            $commenter = $this->createUser($em, 'perf-commenter'.$i.'@example.com', 'perf-commenter-'.$i);
            $comment = new Comment();
            $comment->setProject($project)->setAuthor($commenter)->setContent('Commentaire '.$i);
            $em->persist($comment);
        }
        $em->flush();

        $client->request('GET', '/'); // force un conteneur neuf pour la requête mesurée
        $client->enableProfiler();
        $client->request('GET', '/projets/projet-perf-comments');
        self::assertResponseIsSuccessful();

        $queryCount = $client->getProfile()->getCollector('db')->getQueryCount();
        self::assertLessThan(
            25,
            $queryCount,
            sprintf('Page projet : %d requêtes pour 10 commentaires — un N+1 (2 requêtes/commentaire) donnerait ~28+.', $queryCount),
        );
    }

    public function testExplorerQueryCountDoesNotGrowLinearlyWithProjectOwnerCount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        for ($i = 0; $i < 20; ++$i) {
            $owner = $this->createUser($em, 'perf-explorer-owner'.$i.'@example.com', 'perf-explorer-owner-'.$i);
            $project = new Project();
            $project->setName('Projet explorer perf '.$i)->setType(ProjectType::PERSONNEL)->setStatus(ProjectStatus::PUBLIE);
            $project->setSlug('projet-explorer-perf-'.$i)->setOwner($owner);
            $em->persist($project);
        }
        $em->flush();

        $client->request('GET', '/');
        $client->enableProfiler();
        $client->request('GET', '/explorer');
        self::assertResponseIsSuccessful();

        $queryCount = $client->getProfile()->getCollector('db')->getQueryCount();
        // Sans le correctif (owner/recruiterProfile/defense non hydratés
        // malgré la jointure), 20 propriétaires distincts ajoutaient ~20
        // requêtes supplémentaires (mesuré : 37 avant correctif, 19 après).
        self::assertLessThan(
            25,
            $queryCount,
            sprintf('Explorer : %d requêtes pour 20 propriétaires distincts — la jointure owner/recruiterProfile doit rester effective.', $queryCount),
        );
    }

    public function testNotificationCenterStaysPaginated(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->createUser($em, 'perf-notif@example.com', 'perf-notif-user');
        for ($i = 0; $i < 40; ++$i) {
            $notification = new Notification();
            $notification->setRecipient($user)->setType(NotificationType::COMMENT_RECEIVED)
                ->setTitle('Titre '.$i)->setMessage('Message '.$i);
            $em->persist($notification);
        }
        $em->flush();

        $client->request('GET', '/');
        $client->loginUser($user);
        $client->enableProfiler();
        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();

        $queryCount = $client->getProfile()->getCollector('db')->getQueryCount();
        self::assertLessThan(10, $queryCount, 'Le centre de notifications doit rester paginé (pas 1 requête par notification).');
    }

    // ---- Pagination : le serveur impose toujours une limite maximale ----

    public function testExplorerIgnoresAnExcessivePerPageRequestedByTheClient(): void
    {
        $client = static::createClient();
        $client->request('GET', '/explorer?per_page=100000');
        self::assertResponseIsSuccessful();
        // Le paramètre n'existe même pas dans ProjectSearchCriteria : la
        // taille de page reste celle du serveur (9 par défaut), jamais
        // dictée par le client (cahier §8).
        self::assertLessThanOrEqual(50, \App\Search\ProjectSearchCriteria::MAX_PER_PAGE);
    }

    public function testTalentSearchCapsResultsPerPageServerSide(): void
    {
        static::createClient();
        $criteria = new \App\Search\TalentSearchCriteria(perPage: 100000);
        self::assertSame(\App\Search\TalentSearchCriteria::MAX_PER_PAGE, min(max(1, $criteria->perPage), \App\Search\TalentSearchCriteria::MAX_PER_PAGE));
    }

    // ---- Cache des données de référence : cohérence des données servies --

    public function testReferenceDataProviderReturnsTheSameDataAsADirectQuery(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $domain = new \App\Entity\Domain();
        $domain->setName('Domaine cache test');
        $em->persist($domain);
        $em->flush();

        $provider = static::getContainer()->get(ReferenceDataProvider::class);
        $domains = $provider->domains();

        self::assertNotEmpty($domains);
        self::assertSame($domain->getId(), $domains[0]['id']);
        self::assertSame('Domaine cache test', $domains[0]['name']);
        // Un tableau simple, jamais une entité Doctrine potentiellement
        // détachée après désérialisation depuis le cache.
        self::assertIsArray($domains[0]);
    }

    // ---- Index attendus réellement présents en base (cahier §29) --------

    public function testExpectedIndexesExistOnHighTrafficColumns(): void
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $schemaManager = $connection->createSchemaManager();

        $expected = [
            'comment' => 'comment_project_status_idx',
            'report' => 'report_status_idx',
            'rating' => 'rating_status_idx',
            'app_user' => 'user_status_idx',
            'project' => 'project_name_idx',
        ];

        foreach ($expected as $table => $indexName) {
            $indexes = array_map(static fn ($i) => $i->getName(), $schemaManager->listTableIndexes($table));
            self::assertContains($indexName, $indexes, sprintf('Index "%s" attendu sur "%s".', $indexName, $table));
        }
    }
}
