<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\JuryMember;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\JuryRole;
use App\Enum\JuryStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Couvre la matrice de tests exigée par le prompt « Gestion des accès et des
 * rôles MOUMTOU » : chaque profil ne doit accéder qu'à ce qui lui est
 * réellement destiné, et un accès direct par URL à une ressource d'autrui
 * doit être bloqué côté serveur (pas seulement caché côté Twig).
 */
class AccessControlTest extends FunctionalTestCase
{
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

    // ---- Visiteur --------------------------------------------------------

    public function testVisitorCanReachPublicPages(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/explorer');
        self::assertResponseIsSuccessful();
    }

    #[DataProvider('protectedRoutesProvider')]
    public function testVisitorIsRedirectedToLoginOnProtectedRoutes(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseRedirects('/connexion');
    }

    public static function protectedRoutesProvider(): iterable
    {
        yield 'publier' => ['/publier'];
        yield 'ma-soutenance' => ['/ma-soutenance'];
        yield 'recruteur' => ['/recruteur'];
        yield 'admin' => ['/admin'];
        yield 'espace enseignant' => ['/mon-espace-enseignant'];
    }

    // ---- Talent ------------------------------------------------------------

    public function testTalentAccessesOwnSpaceButNotOtherRoleSpaces(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent@example.com', ['ROLE_TALENT'], 'talent-un');
        $em->flush();
        $client->loginUser($talent);

        $client->request('GET', '/publier');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/ma-soutenance');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/recruteur');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/mon-espace-enseignant');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTalentCannotEditOrDeleteAnotherTalentsProjectByDirectUrl(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner@example.com', ['ROLE_TALENT'], 'owner-un');
        $intruder = $this->makeUser($em, 'intruder@example.com', ['ROLE_TALENT'], 'intrus-un');

        $project = new Project();
        $project->setName('Projet du propriétaire');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-proprietaire');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $client->loginUser($intruder);

        // Tentative d'accès direct à l'URL d'édition d'un projet qui n'appartient pas à l'utilisateur connecté.
        $client->request('GET', '/projets/projet-proprietaire/modifier');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/projets/projet-proprietaire/supprimer', [
            '_csrf_token' => 'peu-importe',
        ]);
        self::assertResponseStatusCodeSame(403);

        // Le propriétaire, lui, y accède normalement.
        $client->loginUser($owner);
        $client->request('GET', '/projets/projet-proprietaire/modifier');
        self::assertResponseIsSuccessful();
    }

    public function testTalentCannotDeleteAnotherUsersComment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner2@example.com', ['ROLE_TALENT'], 'owner-deux');
        $author = $this->makeUser($em, 'author@example.com', ['ROLE_TALENT'], 'auteur-un');
        $intruder = $this->makeUser($em, 'intruder2@example.com', ['ROLE_TALENT'], 'intrus-deux');

        $project = new Project();
        $project->setName('Projet commenté');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-commente');
        $project->setOwner($owner);
        $em->persist($project);

        $comment = new Comment();
        $comment->setProject($project);
        $comment->setAuthor($author);
        $comment->setContent('Bravo pour ce projet !');
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->getId();

        $client->loginUser($intruder);
        $client->request('POST', '/commentaires/'.$commentId.'/supprimer', ['_csrf_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($em->getRepository(Comment::class)->find($commentId), 'Le commentaire ne doit pas avoir été supprimé.');
    }

    // ---- Enseignant / membre du jury ---------------------------------------

    public function testTeacherAccessesOwnSpaceButNotOtherRoleSpaces(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $teacher = $this->makeUser($em, 'teacher@example.com', ['ROLE_TEACHER'], 'enseignant-un');
        $em->flush();
        $client->loginUser($teacher);

        $client->request('GET', '/mon-espace-enseignant');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/publier');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/recruteur');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherCanOnlyRespondToTheirOwnJuryInvitation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $invitedTeacher = $this->makeUser($em, 'invited@example.com', ['ROLE_TEACHER'], 'invite-un');
        $otherTeacher = $this->makeUser($em, 'other@example.com', ['ROLE_TEACHER'], 'autre-enseignant');
        $projectOwner = $this->makeUser($em, 'porteur@example.com', ['ROLE_TALENT'], 'porteur-un');

        $project = new Project();
        $project->setName('Soutenance à juger');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('soutenance-a-juger');
        $project->setOwner($projectOwner);
        $em->persist($project);

        $defense = new \App\Entity\Defense();
        $defense->setProject($project);
        $defense->setDate(new \DateTimeImmutable('2026-10-01'));
        $defense->setTime('10:00');
        $defense->setPlace('Amphi A');
        $project->setDefense($defense);
        $em->persist($defense);

        $juryMember = new JuryMember();
        $juryMember->setFirstName('Invité');
        $juryMember->setLastName('Enseignant');
        $juryMember->setEmail('invited@example.com');
        $juryMember->setRole(JuryRole::PRESIDENT);
        $juryMember->setStatus(JuryStatus::EN_ATTENTE);
        $juryMember->setInvitedUser($invitedTeacher);
        $defense->addJuryMember($juryMember);
        $em->persist($juryMember);
        $em->flush();
        $juryMemberId = $juryMember->getId();

        // Un autre enseignant, non invité, ne peut pas répondre à l'invitation d'un tiers.
        $client->loginUser($otherTeacher);
        $client->request('POST', '/mon-espace-enseignant/jury/'.$juryMemberId.'/repondre', [
            '_csrf_token' => 'peu-importe',
            'decision' => 'confirmer',
        ]);
        self::assertResponseStatusCodeSame(403);

        // L'enseignant réellement invité, lui, apparaît dans son propre tableau de bord.
        $client->loginUser($invitedTeacher);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Soutenance à juger');
    }

    // ---- Recruteur ----------------------------------------------------------

    public function testRecruiterAccessesSearchButNotOtherRoleSpaces(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $recruiter = $this->makeUser($em, 'recruiter@example.com', ['ROLE_RECRUITER'], 'recruteur-un');
        $em->flush();
        $client->loginUser($recruiter);

        $client->request('GET', '/recruteur');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/publier');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/mon-espace-enseignant');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    // ---- Administrateur -------------------------------------------------

    public function testAdminAccessesAdminPanelAndNonAdminIsDenied(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.test@example.com', ['ROLE_ADMIN'], 'admin-test');
        $talent = $this->makeUser($em, 'talent.notadmin@example.com', ['ROLE_TALENT'], 'talent-notadmin');
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $client->loginUser($talent);
        $client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    // ---- Redirection post-connexion selon le profil --------------------

    public function testLoginRedirectsEachProfileToItsOwnSpace(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'redir.talent@example.com', ['ROLE_TALENT'], 'redir-talent');
        $teacher = $this->makeUser($em, 'redir.teacher@example.com', ['ROLE_TEACHER'], 'redir-teacher');
        $recruiter = $this->makeUser($em, 'redir.recruiter@example.com', ['ROLE_RECRUITER'], 'redir-recruiter');
        $admin = $this->makeUser($em, 'redir.admin@example.com', ['ROLE_ADMIN'], 'redir-admin');
        $em->flush();

        $this->assertLoginRedirectsTo($client, $talent, '/profils/redir-talent');
        $this->assertLoginRedirectsTo($client, $teacher, '/mon-espace-enseignant');
        $this->assertLoginRedirectsTo($client, $recruiter, '/recruteur');
        $this->assertLoginRedirectsTo($client, $admin, '/admin');
    }

    private function assertLoginRedirectsTo(KernelBrowser $client, User $user, string $expectedPath): void
    {
        $client->request('GET', '/deconnexion');

        $crawler = $client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $user->getEmail(),
            '_password' => 'MotDePasse123',
        ]);
        $client->submit($form);

        self::assertResponseRedirects($expectedPath);
    }
}
