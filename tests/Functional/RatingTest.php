<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\Rating;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §2 à §10 : notation des projets, un vote par
 * utilisateur, modification, suppression, moyenne recalculée côté serveur.
 */
class RatingTest extends FunctionalTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)->setFirstName('Test')->setLastName(ucfirst($slug))
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function makeProject(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project();
        $project->setName('Projet '.$slug);
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testUserCanRateAndAverageIsRecomputedFromPersistedData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.rate1@example.com', 'owner-rate1');
        $project = $this->makeProject($em, $owner, 'projet-notation');

        $voterA = $this->makeUser($em, 'voter.a@example.com', 'voter-a');
        $voterB = $this->makeUser($em, 'voter.b@example.com', 'voter-b');
        $em->flush();

        $client->loginUser($voterA);
        $crawlerA = $client->request('GET', '/projets/projet-notation');
        $tokenA = $crawlerA->filter('form[action="/projets/projet-notation/noter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-notation/noter', ['value' => '5', '_csrf_token' => $tokenA]);
        self::assertResponseRedirects('/projets/projet-notation');

        $client->loginUser($voterB);
        $crawlerB = $client->request('GET', '/projets/projet-notation');
        $tokenB = $crawlerB->filter('form[action="/projets/projet-notation/noter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-notation/noter', ['value' => '3', '_csrf_token' => $tokenB]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($project->getId());
        self::assertSame(2, $refreshed->getRatingsCount());
        self::assertSame(4.0, $refreshed->getRatingAverage());
    }

    public function testInvalidRatingValueIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.rate2@example.com', 'owner-rate2');
        $project = $this->makeProject($em, $owner, 'projet-note-invalide');
        $voter = $this->makeUser($em, 'voter.invalide@example.com', 'voter-invalide');
        $em->flush();

        $client->loginUser($voter);
        $crawler = $client->request('GET', '/projets/projet-note-invalide');
        $token = $crawler->filter('form[action="/projets/projet-note-invalide/noter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-note-invalide/noter', ['value' => '7', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(Project::class)->find($project->getId())->getRatingsCount());
    }

    public function testSecondVoteUpdatesTheSameRatingRatherThanCreatingADuplicate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.rate3@example.com', 'owner-rate3');
        $project = $this->makeProject($em, $owner, 'projet-modif-note');
        $voter = $this->makeUser($em, 'voter.modif@example.com', 'voter-modif');
        $em->flush();

        $client->loginUser($voter);
        $crawler = $client->request('GET', '/projets/projet-modif-note');
        $token = $crawler->filter('form[action="/projets/projet-modif-note/noter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-modif-note/noter', ['value' => '4', '_csrf_token' => $token]);
        $client->request('POST', '/projets/projet-modif-note/noter', ['value' => '5', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($project->getId());
        self::assertSame(1, $refreshed->getRatingsCount(), 'Un seul vote doit exister par utilisateur et par projet.');
        self::assertSame(5.0, $refreshed->getRatingAverage());

        $ratings = $em->getRepository(Rating::class)->findBy(['project' => $refreshed, 'user' => $voter]);
        self::assertCount(1, $ratings);
    }

    public function testUserCanDeleteTheirRatingAndAverageIsRecomputed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.rate4@example.com', 'owner-rate4');
        $project = $this->makeProject($em, $owner, 'projet-suppr-note');
        $voter = $this->makeUser($em, 'voter.suppr@example.com', 'voter-suppr');
        $em->flush();

        $client->loginUser($voter);
        $crawler = $client->request('GET', '/projets/projet-suppr-note');
        $token = $crawler->filter('form[action="/projets/projet-suppr-note/noter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-suppr-note/noter', ['value' => '2', '_csrf_token' => $token]);

        $crawler = $client->request('GET', '/projets/projet-suppr-note');
        $tokenSuppr = $crawler->filter('form[action="/projets/projet-suppr-note/annuler-note"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-suppr-note/annuler-note', ['_csrf_token' => $tokenSuppr]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($project->getId());
        self::assertSame(0, $refreshed->getRatingsCount());
        self::assertSame(0.0, $refreshed->getRatingAverage());
        self::assertNull($em->getRepository(Rating::class)->findOneBy(['project' => $refreshed, 'user' => $voter]));
    }

    public function testOwnerCannotRateTheirOwnProject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.rate5@example.com', 'owner-rate5');
        $project = $this->makeProject($em, $owner, 'projet-auto-note');
        $voter = $this->makeUser($em, 'voter.rate5@example.com', 'voter-rate5');
        $em->flush();

        // Le jeton CSRF est lié à la session, pas à l'identité : on le
        // récupère via un compte qui voit bien le formulaire de notation.
        $client->loginUser($voter);
        $crawler = $client->request('GET', '/projets/projet-auto-note');
        $token = $crawler->filter('form[action="/projets/projet-auto-note/noter"] input[name="_csrf_token"]')->attr('value');

        $client->loginUser($owner);
        $crawlerOwner = $client->request('GET', '/projets/projet-auto-note');
        // Le formulaire de notation n'est pas rendu pour le propriétaire.
        self::assertCount(0, $crawlerOwner->filter('form[action="/projets/projet-auto-note/noter"]'));

        $client->request('POST', '/projets/projet-auto-note/noter', ['value' => '5', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(Project::class)->find($project->getId())->getRatingsCount());
    }

    public function testAnonymousVisitorSeesAverageButCannotVote(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.rate6@example.com', 'owner-rate6');
        $this->makeProject($em, $owner, 'projet-visiteur');

        $crawler = $client->request('GET', '/projets/projet-visiteur');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Connectez-vous');

        $client->request('POST', '/projets/projet-visiteur/noter', ['value' => '5']);
        self::assertResponseRedirects('/connexion');
    }
}
