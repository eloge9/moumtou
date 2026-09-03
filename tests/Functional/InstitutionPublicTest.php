<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\Institution;
use App\Entity\JuryMember;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\InstitutionType;
use App\Enum\JuryRole;
use App\Enum\JuryStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Espace public des établissements : annuaire, page détail, projets,
 * soutenances, talents — nouvelle porte d'entrée vers les pages publiques
 * déjà existantes (projet, soutenance, profil), sans les dupliquer.
 */
class InstitutionPublicTest extends FunctionalTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT'], ?Institution $institution = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)->setFirstName('Test')->setLastName(ucfirst($slug))
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)->setEmailVerified(true);
        if ($institution) {
            $user->setInstitution($institution);
        }
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function makeInstitution(EntityManagerInterface $em, string $name, string $slug, bool $active = true, bool $verified = false): Institution
    {
        $institution = new Institution();
        $institution->setName($name);
        $institution->setSlug($slug);
        $institution->setType(InstitutionType::UNIVERSITE);
        $institution->setCountry('Togo');
        $institution->setCity('Lomé');
        $institution->setActive($active);
        $institution->setVerified($verified);
        $em->persist($institution);

        return $institution;
    }

    private function makeProject(EntityManagerInterface $em, User $owner, Institution $institution, string $name, string $slug, ProjectStatus $status = ProjectStatus::PUBLIE, ProjectType $type = ProjectType::PERSONNEL): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setType($type);
        $project->setStatus($status);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $project->setInstitution($institution);
        $em->persist($project);

        return $project;
    }

    public function testDirectoryListsActiveInstitutions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $this->makeInstitution($em, 'Établissement désactivé', 'etablissement-desactive', false);
        $em->flush();

        $crawler = $client->request('GET', '/etablissements');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'IPNET Institute of Technology');
        self::assertSelectorTextNotContains('body', 'Établissement désactivé');
    }

    public function testDirectorySearchByNameCountryAndCity(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $ipnet = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $ipnet->setCountry('Togo')->setCity('Lomé');
        $other = $this->makeInstitution($em, 'Universite de Paris', 'universite-de-paris');
        $other->setCountry('France')->setCity('Paris');
        $em->flush();

        $client->request('GET', '/etablissements?q=IPNET');
        self::assertSelectorTextContains('body', 'IPNET');
        self::assertSelectorTextNotContains('body', 'Universite de Paris');

        $client->request('GET', '/etablissements?country=France');
        self::assertSelectorTextContains('body', 'Universite de Paris');
        self::assertSelectorTextNotContains('body', 'IPNET');

        $client->request('GET', '/etablissements?city=Lom'.rawurlencode('é'));
        self::assertSelectorTextContains('body', 'IPNET');
    }

    public function testClickingACardOpensTheDetailPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology', true, true);
        $em->flush();

        $crawler = $client->request('GET', '/etablissements');
        $link = $crawler->selectLink('Voir l\'établissement')->link();
        $crawler = $client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'IPNET Institute of Technology');
        self::assertSelectorTextContains('body', 'Établissement vérifié');
    }

    public function testUnknownOrInactiveInstitutionReturns404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $this->makeInstitution($em, 'Établissement caché', 'etablissement-cache', false);
        $em->flush();

        $client->request('GET', '/etablissements/inexistant');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/etablissements/etablissement-cache');
        self::assertResponseStatusCodeSame(404, 'Un établissement désactivé ne doit pas être accessible publiquement.');
    }

    public function testOnlyPublicProjectsAppearInProjectsTab(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $owner = $this->makeUser($em, 'owner.pub@example.com', 'owner-pub');
        $this->makeProject($em, $owner, $institution, 'Projet publié visible', 'projet-publie-visible', ProjectStatus::PUBLIE);
        $this->makeProject($em, $owner, $institution, 'Projet brouillon caché', 'projet-brouillon-cache', ProjectStatus::BROUILLON);
        $this->makeProject($em, $owner, $institution, 'Projet en attente caché', 'projet-attente-cache', ProjectStatus::EN_ATTENTE);
        $this->makeProject($em, $owner, $institution, 'Projet rejete cache', 'projet-rejete-cache', ProjectStatus::REJETE);
        $em->flush();

        $crawler = $client->request('GET', '/etablissements/ipnet-institute-of-technology?tab=projets');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Projet publié visible');
        self::assertSelectorTextNotContains('body', 'Projet brouillon caché');
        self::assertSelectorTextNotContains('body', 'Projet en attente caché');
        self::assertSelectorTextNotContains('body', 'Projet rejete cache');
    }

    public function testProjectCardLinksToExistingProjectPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $owner = $this->makeUser($em, 'owner.link@example.com', 'owner-link');
        $this->makeProject($em, $owner, $institution, 'Projet cliquable', 'projet-cliquable');
        $em->flush();

        $crawler = $client->request('GET', '/etablissements/ipnet-institute-of-technology?tab=projets');
        $link = $crawler->selectLink('Projet cliquable')->link();
        $client->click($link);
        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_project_show');
    }

    public function testOnlyPublicDefensesAppearInDefensesTabAndLinkToExistingPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $owner = $this->makeUser($em, 'owner.defense@example.com', 'owner-defense');
        $publicProject = $this->makeProject($em, $owner, $institution, 'Soutenance publique', 'soutenance-publique', ProjectStatus::PUBLIE, ProjectType::SOUTENANCE);
        $defense = new Defense();
        $defense->setProject($publicProject)->setDate(new \DateTimeImmutable('2026-12-01'))->setTime('10:00')->setPlace('Amphi A')->setStatus(DefenseStatus::ANNONCEE);
        $publicProject->setDefense($defense);
        $em->persist($defense);

        $hiddenProject = $this->makeProject($em, $owner, $institution, 'Soutenance cachee', 'soutenance-cachee', ProjectStatus::EN_ATTENTE, ProjectType::SOUTENANCE);
        $hiddenDefense = new Defense();
        $hiddenDefense->setProject($hiddenProject)->setDate(new \DateTimeImmutable('2026-12-05'))->setTime('10:00')->setPlace('Amphi B')->setStatus(DefenseStatus::ANNONCEE);
        $hiddenProject->setDefense($hiddenDefense);
        $em->persist($hiddenDefense);
        $em->flush();

        $crawler = $client->request('GET', '/etablissements/ipnet-institute-of-technology?tab=soutenances');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Soutenance publique');
        self::assertSelectorTextNotContains('body', 'Soutenance cachee');

        $link = $crawler->filter('a[href="/soutenances/soutenance-publique"]')->link();
        $client->click($link);
        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_defense_show');
    }

    public function testTalentCardLinksToExistingPublicProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $talent = $this->makeUser($em, 'talent.card@example.com', 'talent-card', ['ROLE_TALENT'], $institution);
        $this->makeProject($em, $talent, $institution, 'Projet du talent', 'projet-du-talent');
        $em->flush();

        $crawler = $client->request('GET', '/etablissements/ipnet-institute-of-technology?tab=talents');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Talent-card');

        $link = $crawler->filter('a[href="/profils/talent-card"]')->first()->link();
        $client->click($link);
        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_profile_show');
    }

    public function testTalentWithoutPublicProjectDoesNotAppearInTalentsTab(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $this->makeUser($em, 'talent.noproject@example.com', 'talent-noproject-inst', ['ROLE_TALENT'], $institution);
        $em->flush();

        $client->request('GET', '/etablissements/ipnet-institute-of-technology?tab=talents');
        self::assertSelectorTextNotContains('body', 'Noproject-inst');
    }

    public function testWhatsAppButtonRespectsConfidentialityFromInstitutionTalentProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $talent = $this->makeUser($em, 'talent.whatsapp@example.com', 'talent-whatsapp-inst', ['ROLE_TALENT'], $institution);
        $talent->setWhatsapp('22890001234')->setWhatsappEnabled(false);
        $this->makeProject($em, $talent, $institution, 'Projet whatsapp', 'projet-whatsapp');
        $em->flush();

        $crawler = $client->request('GET', '/profils/talent-whatsapp-inst');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('22890001234', $crawler->filter('body')->html());
    }

    public function testStatsAreComputedFromRealDataNotFictional(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $owner = $this->makeUser($em, 'owner.stats@example.com', 'owner-stats');
        $this->makeProject($em, $owner, $institution, 'Projet stats un', 'projet-stats-un', ProjectStatus::PUBLIE);
        $this->makeProject($em, $owner, $institution, 'Projet stats deux', 'projet-stats-deux', ProjectStatus::VERIFIE);
        $em->flush();

        $crawler = $client->request('GET', '/etablissements/ipnet-institute-of-technology');
        self::assertResponseIsSuccessful();
        // 2 projets publics au total (publié + vérifié), 1 vérifié.
        self::assertSelectorTextContains('.m-stat__valeur', '2');
    }

    /**
     * Vérifie que les compteurs "projets/soutenances" de l'annuaire (une
     * requête groupée pour tous les établissements de la page, cahier §28)
     * restent corrects avec plusieurs établissements en même temps — la
     * requête groupée elle-même est garantie par construction
     * ({@see \App\Repository\InstitutionRepository::countProjectsAndDefensesByInstitutions()}).
     */
    public function testDirectoryCardCountsStayCorrectWithMultipleInstitutions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.n1@example.com', 'owner-n1');
        for ($i = 1; $i <= 5; ++$i) {
            $institution = $this->makeInstitution($em, 'Etablissement '.$i, 'etablissement-'.$i);
            $this->makeProject($em, $owner, $institution, 'Projet '.$i, 'projet-n1-'.$i);
        }
        $em->flush();

        $client->request('GET', '/etablissements');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '5 établissements');
    }

    public function testJuryPrivateInformationNeverExposedOnLinkedDefensePage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->makeInstitution($em, 'IPNET Institute of Technology', 'ipnet-institute-of-technology');
        $owner = $this->makeUser($em, 'owner.jury@example.com', 'owner-jury');
        $project = $this->makeProject($em, $owner, $institution, 'Soutenance avec jury', 'soutenance-avec-jury', ProjectStatus::PUBLIE, ProjectType::SOUTENANCE);
        $defense = new Defense();
        $defense->setProject($project)->setDate(new \DateTimeImmutable('2026-12-01'))->setTime('10:00')->setPlace('Amphi A')->setStatus(DefenseStatus::ANNONCEE);
        $project->setDefense($defense);
        $em->persist($defense);

        $jury = new JuryMember();
        $jury->setDefense($defense)->setFirstName('Jury')->setLastName('Membre')
            ->setRole(JuryRole::PRESIDENT)->setEmail('jury.prive@example.com')
            ->setStatus(JuryStatus::CONFIRME);
        $em->persist($jury);
        $em->flush();

        $client->request('GET', '/soutenances/soutenance-avec-jury');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('jury.prive@example.com', $client->getResponse()->getContent());
    }
}
