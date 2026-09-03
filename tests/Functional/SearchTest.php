<?php

namespace App\Tests\Functional;

use App\Entity\Domain;
use App\Entity\Project;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\Availability;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 6 : recherche avancée et découverte
 * (talents, projets, soutenances, technologies, institutions).
 */
class SearchTest extends FunctionalTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT'], array $overrides = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)->setFirstName($overrides['firstName'] ?? 'Test')->setLastName($overrides['lastName'] ?? ucfirst($slug))
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)->setEmailVerified(true);
        if (isset($overrides['country'])) {
            $user->setCountry($overrides['country']);
        }
        if (isset($overrides['city'])) {
            $user->setCity($overrides['city']);
        }
        if (isset($overrides['availability'])) {
            $user->setAvailability($overrides['availability']);
        }
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function makeProject(EntityManagerInterface $em, User $owner, string $name, string $slug, array $technologies = [], ProjectType $type = ProjectType::PERSONNEL, ProjectStatus $status = ProjectStatus::PUBLIE): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setType($type);
        $project->setStatus($status);
        $project->setSlug($slug);
        $project->setOwner($owner);
        foreach ($technologies as $technology) {
            $project->addTechnology($technology);
        }
        $em->persist($project);

        return $project;
    }

    private function makeTechnology(EntityManagerInterface $em, string $name): Technology
    {
        $tech = (new Technology())->setName($name);
        $em->persist($tech);

        return $tech;
    }

    public function testExplorerSearchesByProjectNameAndDescription(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search1@example.com', 'owner-search1');
        $p1 = $this->makeProject($em, $owner, 'Gestion universitaire', 'gestion-universitaire');
        $p1->setShortDescription('Application de gestion des inscriptions.');
        $this->makeProject($em, $owner, 'Autre chose', 'autre-chose');
        $em->flush();

        $client->request('GET', '/explorer?q=universitaire');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Gestion universitaire');
        self::assertSelectorTextNotContains('body', 'Autre chose');
    }

    public function testExplorerTechnologyAllVsAnyLogic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search2@example.com', 'owner-search2');
        $angular = $this->makeTechnology($em, 'Angular');
        $java = $this->makeTechnology($em, 'Java');
        $spring = $this->makeTechnology($em, 'Spring Boot');

        $this->makeProject($em, $owner, 'Projet complet', 'projet-complet', [$angular, $java, $spring]);
        $this->makeProject($em, $owner, 'Projet partiel', 'projet-partiel', [$angular]);
        $em->flush();

        // "Au moins une" (any) : les deux projets ressortent.
        $crawler = $client->request('GET', '/explorer?'.http_build_query(['technologies' => [$angular->getId()]]));
        self::assertSelectorTextContains('body', 'Projet complet');
        self::assertSelectorTextContains('body', 'Projet partiel');

        // "Toutes" (all) : seul le projet qui a les 3 technologies ressort.
        $client->request('GET', '/explorer?'.http_build_query(['technologies' => [$angular->getId(), $java->getId(), $spring->getId()], 'tech_mode' => 'all']));
        self::assertSelectorTextContains('body', 'Projet complet');
        self::assertSelectorTextNotContains('body', 'Projet partiel');
    }

    public function testExplorerVerifiedProjectFilterDoesNotIncludeMerePublished(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search3@example.com', 'owner-search3');
        $this->makeProject($em, $owner, 'Projet publié seulement', 'projet-publie-seul', [], ProjectType::PERSONNEL, ProjectStatus::PUBLIE);
        $this->makeProject($em, $owner, 'Projet vérifié', 'projet-verifie', [], ProjectType::PERSONNEL, ProjectStatus::VERIFIE);
        $em->flush();

        $client->request('GET', '/explorer?'.http_build_query(['statuses' => ['verifie']]));
        self::assertSelectorTextContains('body', 'Projet vérifié');
        self::assertSelectorTextNotContains('body', 'Projet publié seulement');
    }

    public function testExplorerNeverReturnsUnpublishedOrHiddenProjects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search4@example.com', 'owner-search4');
        $this->makeProject($em, $owner, 'Projet masqué', 'projet-masque', [], ProjectType::PERSONNEL, ProjectStatus::REJETE);
        $this->makeProject($em, $owner, 'Projet en attente', 'projet-attente', [], ProjectType::PERSONNEL, ProjectStatus::EN_ATTENTE);
        $em->flush();

        $client->request('GET', '/explorer');
        self::assertSelectorTextNotContains('body', 'Projet masqué');
        self::assertSelectorTextNotContains('body', 'Projet en attente');
    }

    public function testExplorerDomainFilter(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search5@example.com', 'owner-search5');
        $domain = (new Domain())->setName('Informatique');
        $em->persist($domain);
        $otherDomain = (new Domain())->setName('Commerce');
        $em->persist($otherDomain);

        $p1 = $this->makeProject($em, $owner, 'Projet info', 'projet-info');
        $p1->setDomain($domain);
        $p2 = $this->makeProject($em, $owner, 'Projet commerce', 'projet-commerce');
        $p2->setDomain($otherDomain);
        $em->flush();

        $client->request('GET', '/explorer?domain='.$domain->getId());
        self::assertSelectorTextContains('body', 'Projet info');
        self::assertSelectorTextNotContains('body', 'Projet commerce');
    }

    public function testExplorerPaginationLimitsResultsPerPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search6@example.com', 'owner-search6');
        for ($i = 1; $i <= 12; ++$i) {
            $this->makeProject($em, $owner, 'Projet pagination '.$i, 'projet-pagination-'.$i);
        }
        $em->flush();

        $crawler = $client->request('GET', '/explorer');
        self::assertResponseIsSuccessful();
        self::assertCount(9, $crawler->filter('.m-carte__titre'));

        $client->request('GET', '/explorer?page=2');
        self::assertResponseIsSuccessful();
    }

    public function testRecruiterSearchRequiresRecruiterRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.search1@example.com', 'talent-search1');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/recruteur');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRecruiterCanSearchTalentsByTechnologyAndCountry(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $angular = $this->makeTechnology($em, 'Angular');
        $talentTogo = $this->makeUser($em, 'talent.togo@example.com', 'talent-togo', ['ROLE_TALENT'], ['country' => 'Togo']);
        $talentTogo->addTechnology($angular);
        $this->makeProject($em, $talentTogo, 'Projet talent togo', 'projet-talent-togo');

        $talentFrance = $this->makeUser($em, 'talent.france@example.com', 'talent-france', ['ROLE_TALENT'], ['country' => 'France']);
        $talentFrance->addTechnology($angular);
        $this->makeProject($em, $talentFrance, 'Projet talent france', 'projet-talent-france');

        $recruiter = $this->makeUser($em, 'recruiter.search1@example.com', 'recruiter-search1', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/recruteur?'.http_build_query(['technologies' => [$angular->getId()], 'country' => 'Togo']));
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/profils/talent-togo"]')->count());
        self::assertCount(0, $crawler->filter('a[href="/profils/talent-france"]'));
    }

    public function testTalentWithoutPublicProjectDoesNotAppearInSearch(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talentNoProject = $this->makeUser($em, 'talent.noproject@example.com', 'talent-noproject');
        $recruiter = $this->makeUser($em, 'recruiter.search2@example.com', 'recruiter-search2', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $client->request('GET', '/recruteur');
        self::assertSelectorTextNotContains('body', 'Noproject');
    }

    public function testRecruiterSearchByAvailabilityAndPaginates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        for ($i = 1; $i <= 15; ++$i) {
            $talent = $this->makeUser($em, 'talent.avail'.$i.'@example.com', 'talent-avail-'.$i, ['ROLE_TALENT'], ['availability' => Availability::STAGE]);
            $this->makeProject($em, $talent, 'Projet dispo '.$i, 'projet-dispo-'.$i);
        }
        $recruiter = $this->makeUser($em, 'recruiter.search3@example.com', 'recruiter-search3', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/recruteur?availability=stage');
        self::assertResponseIsSuccessful();
        self::assertCount(12, $crawler->filter('article.m-carte.m-carte--plate'));
    }

    public function testPublicSearchEmptyStateShowsDiscoveryContent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search7@example.com', 'owner-search7');
        $this->makeProject($em, $owner, 'Projet découverte', 'projet-decouverte');
        $em->flush();

        $client->request('GET', '/recherche');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Découvrez MOUMTOU');
    }

    public function testPublicSearchOverviewGroupsResultsByCategory(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $angular = $this->makeTechnology($em, 'Angular');
        $owner = $this->makeUser($em, 'owner.search8@example.com', 'owner-search8', ['ROLE_TALENT'], ['firstName' => 'Angular', 'lastName' => 'Talent']);
        $owner->addTechnology($angular);
        $this->makeProject($em, $owner, 'Projet Angular', 'projet-angular', [$angular]);
        $em->flush();

        $client->request('GET', '/recherche?q=Angular');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Talents');
        self::assertSelectorTextContains('body', 'Projets');
        self::assertSelectorTextContains('body', 'Soutenances');
        self::assertSelectorTextContains('body', 'Projet Angular');
    }

    public function testPublicSearchIsAccessibleWithoutLogin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $client->request('GET', '/recherche?type=talents');
        self::assertResponseIsSuccessful();
    }

    public function testSuggestionsEndpointReturnsMatchesAndIgnoresShortQueries(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $this->makeTechnology($em, 'Angular');
        $this->makeTechnology($em, 'AngularJS');
        $em->flush();

        $client->request('GET', '/recherche/suggestions?q=a');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['suggestions'], 'Une requête trop courte ne doit déclencher aucune suggestion.');

        $client->request('GET', '/recherche/suggestions?q=Ang');
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(2, \count($data['suggestions']));
    }

    public function testRecruiterCanFilterTalentsByYearAndSeesActiveFilterTags(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $angular = $this->makeTechnology($em, 'Angular');

        $talentRecent = $this->makeUser($em, 'talent.recent@example.com', 'talent-recent');
        $talentRecent->addTechnology($angular);
        $recentProject = $this->makeProject($em, $talentRecent, 'Projet récent', 'projet-recent');
        $recentProject->setRealizationDate(new \DateTimeImmutable('2025-06-01'));

        $talentOld = $this->makeUser($em, 'talent.old@example.com', 'talent-old');
        $talentOld->addTechnology($angular);
        $oldProject = $this->makeProject($em, $talentOld, 'Projet ancien', 'projet-ancien');
        $oldProject->setRealizationDate(new \DateTimeImmutable('2019-06-01'));

        $recruiter = $this->makeUser($em, 'recruiter.year@example.com', 'recruiter-year', ['ROLE_RECRUITER']);
        $em->flush();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/recruteur?'.http_build_query(['technologies' => [$angular->getId()], 'year_min' => 2023]));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/profils/talent-recent"]');
        self::assertSelectorNotExists('a[href="/profils/talent-old"]');
        // Le filtre technologie actif doit apparaître comme tag retirable.
        self::assertSelectorTextContains('a.m-chip.is-active', 'Angular');
    }

    public function testMaliciousLimitAndPageParametersAreClamped(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.search9@example.com', 'owner-search9');
        $this->makeProject($em, $owner, 'Projet sécurité', 'projet-securite');
        $em->flush();

        // Page négative ou absurde : ne doit ni planter ni renvoyer une erreur serveur.
        $client->request('GET', '/explorer?page=-5');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/explorer?page=999999');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/explorer?technologies[]=abc');
        self::assertResponseIsSuccessful();
    }
}
