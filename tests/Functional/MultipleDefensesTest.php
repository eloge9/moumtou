<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Un talent peut avoir publié plusieurs projets de type Soutenance : « Ma
 * soutenance » devient alors une liste, chacune menant à sa propre gestion
 * complète (annoncer/jury/résultat), indépendamment des autres.
 */
class MultipleDefensesTest extends FunctionalTestCase
{
    private function createTalent(EntityManagerInterface $em): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('multi.soutenances@example.com')
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setPhone('+22890000000')
            ->setRoles(['ROLE_TALENT'])
            ->setStatus(UserStatus::ACTIF)
            ->setSlug('jean-dupont-multi')
            ->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function createSoutenanceProject(EntityManagerInterface $em, User $owner, string $name, string $slug): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);

        return $project;
    }

    public function testSingleSoutenanceStillShowsDetailDirectlyWithoutAnExtraList(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->createTalent($em);
        $this->createSoutenanceProject($em, $user, 'Projet unique', 'projet-unique');
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/ma-soutenance');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1, .m-surtitre', 'soutenance');
        // Le formulaire d'annonce doit être directement présent, pas une liste à cliquer.
        self::assertSelectorExists('form[action*="/annoncer"]');
    }

    public function testMultipleSoutenancesShowAListAndEachOwnManagementPageIsIndependent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->createTalent($em);
        $projectA = $this->createSoutenanceProject($em, $user, 'Projet A', 'projet-a-multi');
        $projectB = $this->createSoutenanceProject($em, $user, 'Projet B', 'projet-b-multi');

        $defenseA = new Defense();
        $defenseA->setProject($projectA)->setDate(new \DateTimeImmutable('+10 days'))->setTime('10:00')->setPlace('Salle A')->setStatus(DefenseStatus::ANNONCEE);
        $projectA->setDefense($defenseA);
        $em->persist($defenseA);
        $em->flush();

        $projectAId = $projectA->getId();
        $projectBId = $projectB->getId();

        $client->loginUser($user);

        // La liste apparaît dès qu'il y en a plus d'une.
        $crawler = $client->request('GET', '/ma-soutenance');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Projet A');
        self::assertSelectorTextContains('body', 'Projet B');
        self::assertSelectorTextContains('body', 'Annoncée');
        self::assertSelectorTextContains('body', 'À annoncer');

        // La gestion de A montre son annonce, pas celle de B (qui n'en a pas).
        $client->request('GET', '/ma-soutenance/'.$projectAId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Salle A');

        // B, elle, propose encore le formulaire d'annonce (aucune soutenance liée).
        $crawler = $client->request('GET', '/ma-soutenance/'.$projectBId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action*="/annoncer"]');
    }

    public function testCannotAccessAnotherTalentsDefenseManagementPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createTalent($em);
        $project = $this->createSoutenanceProject($em, $owner, 'Projet privé', 'projet-prive-multi');
        $em->flush();
        $projectId = $project->getId();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = (new User())
            ->setEmail('intrus@example.com')->setFirstName('Intrus')->setLastName('Test')
            ->setPhone('+22890000001')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('intrus-test');
        $intruder->setPassword($hasher->hashPassword($intruder, 'MotDePasse123'));
        $em->persist($intruder);
        $em->flush();

        $client->loginUser($intruder);
        $client->request('GET', '/ma-soutenance/'.$projectId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNonSoutenanceProjectIsNotManageableAsADefense(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->createTalent($em);
        $personalProject = new Project();
        $personalProject->setName('Projet personnel')->setType(ProjectType::PERSONNEL)->setStatus(ProjectStatus::PUBLIE)->setSlug('projet-personnel-multi')->setOwner($user);
        $em->persist($personalProject);
        $em->flush();
        $projectId = $personalProject->getId();

        $client->loginUser($user);
        $client->request('GET', '/ma-soutenance/'.$projectId);
        self::assertResponseStatusCodeSame(404, 'Un projet qui n\'est pas de type Soutenance ne doit jamais être gérable comme une soutenance.');
    }
}
