<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\Institution;
use App\Entity\Project;
use App\Entity\ProjectProof;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\InstitutionType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ProofType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §35/§38 : pages publiques indexables (meta, Open
 * Graph, sitemap).
 */
class SeoTest extends FunctionalTestCase
{
    public function testSitemapListsPublicProjectAndProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new User())->setEmail('seo@example.com')->setFirstName('Seo')->setLastName('Test')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('seo-test');
        $owner->setPassword($hasher->hashPassword($owner, 'MotDePasse123'));
        $em->persist($owner);

        $project = new Project();
        $project->setName('Projet indexable');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-indexable');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/xml', $client->getResponse()->headers->get('Content-Type'));

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('/projets/projet-indexable', $content);
        self::assertStringContainsString('/profils/seo-test', $content);
    }

    public function testProjectPageHasOpenGraphAndStructuredData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new User())->setEmail('seo2@example.com')->setFirstName('Seo')->setLastName('Deux')
            ->setPhone('+22890000001')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('seo-deux');
        $owner->setPassword($hasher->hashPassword($owner, 'MotDePasse123'));
        $em->persist($owner);

        $project = new Project();
        $project->setName('Plateforme de gestion');
        $project->setShortDescription('Une plateforme de test pour le SEO.');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('plateforme-gestion-seo');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $crawler = $client->request('GET', '/projets/plateforme-gestion-seo');
        self::assertResponseIsSuccessful();

        self::assertSame(1, $crawler->filter('meta[property="og:title"]')->count());
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertStringContainsString('schema.org', $client->getResponse()->getContent());
    }

    // ---- Compléments cahier — FONCTIONNALITÉ 13 ---------------------------

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

    private function makeProject(EntityManagerInterface $em, User $owner, string $slug, ProjectStatus $status): Project
    {
        $project = new Project();
        $project->setName('Projet '.$slug);
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus($status);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);

        return $project;
    }

    /**
     * `/robots.txt` est un fichier statique servi directement par le serveur
     * web (hors routage Symfony) : le client de test fonctionnel (qui
     * n'invoque que le kernel) ne peut pas le récupérer par une requête
     * HTTP — on vérifie donc directement son contenu sur le disque.
     */
    public function testRobotsTxtDisallowsPrivateAreasAndReferencesTheSitemap(): void
    {
        static::createClient();
        $path = static::getContainer()->getParameter('kernel.project_dir').'/public/robots.txt';
        self::assertFileExists($path);
        $content = file_get_contents($path);

        foreach (['/admin', '/mon-compte', '/mon-profil', '/publier', '/recruteur', '/connexion', '/inscription', '/notifications'] as $disallowedPath) {
            self::assertStringContainsString('Disallow: '.$disallowedPath, $content);
        }
        self::assertStringContainsString('Sitemap: /sitemap.xml', $content);
    }

    public function testSitemapExcludesNonPublicProjects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.sitemap-exclu@example.com', 'owner-sitemap-exclu');
        $this->makeProject($em, $owner, 'projet-brouillon-sitemap', ProjectStatus::BROUILLON);
        $this->makeProject($em, $owner, 'projet-attente-sitemap', ProjectStatus::EN_ATTENTE);
        $this->makeProject($em, $owner, 'projet-rejete-sitemap', ProjectStatus::REJETE);
        $verified = $this->makeProject($em, $owner, 'projet-verifie-sitemap', ProjectStatus::VERIFIE);
        $em->flush();

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();

        self::assertStringNotContainsString('projet-brouillon-sitemap', $content);
        self::assertStringNotContainsString('projet-attente-sitemap', $content);
        self::assertStringNotContainsString('projet-rejete-sitemap', $content);
        self::assertStringContainsString('projet-verifie-sitemap', $content);
    }

    public function testSitemapIncludesPublicDefensesAndActiveInstitutions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.sitemap-inclu@example.com', 'owner-sitemap-inclu');
        $project = $this->makeProject($em, $owner, 'projet-soutenance-sitemap', ProjectStatus::PUBLIE);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate(new \DateTimeImmutable('+30 days'));
        $defense->setTime('10:00');
        $defense->setPlace('Amphi Sitemap');
        $defense->setStatus(DefenseStatus::ANNONCEE);
        $em->persist($defense);

        $institution = new Institution();
        $institution->setName('Institut Sitemap Actif');
        $institution->setSlug('institut-sitemap-actif');
        $institution->setType(InstitutionType::UNIVERSITE);
        $institution->setActive(true);
        $institution->setVerified(true);
        $em->persist($institution);

        $inactiveInstitution = new Institution();
        $inactiveInstitution->setName('Institut Sitemap Inactif');
        $inactiveInstitution->setSlug('institut-sitemap-inactif');
        $inactiveInstitution->setType(InstitutionType::UNIVERSITE);
        $inactiveInstitution->setActive(false);
        $em->persist($inactiveInstitution);

        $em->flush();

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();

        self::assertStringContainsString('/soutenances/projet-soutenance-sitemap', $content);
        self::assertStringContainsString('/etablissements/institut-sitemap-actif', $content);
        self::assertStringNotContainsString('institut-sitemap-inactif', $content);
    }

    public function testPrivatePagesAreMarkedNoindex(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/connexion');
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));

        $crawler = $client->request('GET', '/inscription');
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testPublicProjectPageRemainsIndexable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.indexable@example.com', 'owner-indexable');
        $this->makeProject($em, $owner, 'projet-indexable-test', ProjectStatus::PUBLIE);
        $em->flush();

        $crawler = $client->request('GET', '/projets/projet-indexable-test');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('meta[name="robots"][content*="noindex"]')->count(), 'A public, published project must never be noindex.');
    }

    public function testProjectPageExposesTwitterCardMetadata(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.twittercard@example.com', 'owner-twittercard');
        $this->makeProject($em, $owner, 'projet-twittercard-test', ProjectStatus::PUBLIE);
        $em->flush();

        $crawler = $client->request('GET', '/projets/projet-twittercard-test');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('meta[name="twitter:card"]')->count());
        self::assertSame(1, $crawler->filter('meta[name="twitter:title"]')->count());
        self::assertSame(1, $crawler->filter('meta[name="twitter:description"]')->count());
    }

    public function testProjectWithGithubProofUsesSoftwareSourceCodeStructuredData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.ssc@example.com', 'owner-ssc');
        $project = $this->makeProject($em, $owner, 'projet-software-source', ProjectStatus::PUBLIE);
        $em->flush();

        $proof = new ProjectProof();
        $proof->setType(ProofType::GITHUB);
        $proof->setUrl('https://github.com/owner/projet-software-source');
        $project->addProof($proof);
        $em->persist($proof);
        $em->flush();

        $client->request('GET', '/projets/projet-software-source');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"SoftwareSourceCode"', $client->getResponse()->getContent());
    }

    public function testProjectWithoutGithubProofUsesCreativeWorkStructuredData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.cw@example.com', 'owner-cw');
        $project = $this->makeProject($em, $owner, 'projet-creative-work', ProjectStatus::PUBLIE);
        $em->flush();

        $proof = new ProjectProof();
        $proof->setType(ProofType::SITE);
        $proof->setUrl('https://example.com/projet-creative-work');
        $project->addProof($proof);
        $em->persist($proof);
        $em->flush();

        $client->request('GET', '/projets/projet-creative-work');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"CreativeWork"', $client->getResponse()->getContent());
        self::assertStringNotContainsString('"SoftwareSourceCode"', $client->getResponse()->getContent());
    }

    public function testProjectPageHasBreadcrumbListStructuredData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.breadcrumb@example.com', 'owner-breadcrumb');
        $this->makeProject($em, $owner, 'projet-breadcrumb-test', ProjectStatus::PUBLIE);
        $em->flush();

        $client->request('GET', '/projets/projet-breadcrumb-test');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"BreadcrumbList"', $client->getResponse()->getContent());
    }

    public function testExplorerHasCanonicalUrlIgnoringFilters(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/explorer?'.http_build_query(['technologies' => [1], 'page' => 2]));
        self::assertResponseIsSuccessful();

        $canonical = $crawler->filter('link[rel="canonical"]');
        self::assertCount(1, $canonical);
        self::assertStringNotContainsString('?', (string) $canonical->attr('href'));
    }

    public function testExplorerCoverImagesHaveNonEmptyAltText(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.alt@example.com', 'owner-alt');
        $project = $this->makeProject($em, $owner, 'projet-alt-test', ProjectStatus::PUBLIE);
        $em->flush();

        $photo = new \App\Entity\ProjectPhoto();
        $photo->setPath('uploads/projects/test/alt.jpg');
        $photo->setPosition(0);
        $project->addPhoto($photo);
        $em->persist($photo);
        $em->flush();

        $crawler = $client->request('GET', '/explorer');
        self::assertResponseIsSuccessful();

        $img = $crawler->filter('img[src*="alt.jpg"]');
        self::assertCount(1, $img);
        self::assertNotEmpty($img->attr('alt'));
        self::assertStringContainsString('Projet '.$project->getSlug(), (string) $img->attr('alt'));
    }

    public function testTalentAndInstitutionPublicPagesExposeSeoMetadata(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.talentseo@example.com', 'owner-talentseo');
        $em->flush();

        $crawler = $client->request('GET', '/profils/owner-talentseo');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('link[rel="canonical"]'));
        self::assertCount(1, $crawler->filter('meta[property="og:title"]'));
        self::assertStringContainsString('"@type":"Person"', $client->getResponse()->getContent());
        self::assertStringContainsString('"BreadcrumbList"', $client->getResponse()->getContent());

        $institution = new Institution();
        $institution->setName('Institut SEO Test');
        $institution->setSlug('institut-seo-test');
        $institution->setType(InstitutionType::UNIVERSITE);
        $institution->setActive(true);
        $institution->setVerified(true);
        $em->persist($institution);
        $em->flush();

        $crawler = $client->request('GET', '/etablissements/institut-seo-test');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('link[rel="canonical"]'));
        self::assertStringContainsString('"CollegeOrUniversity"', $client->getResponse()->getContent());
        self::assertStringContainsString('"BreadcrumbList"', $client->getResponse()->getContent());
    }
}
