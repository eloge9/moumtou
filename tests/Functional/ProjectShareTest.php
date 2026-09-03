<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\Institution;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\InstitutionType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 11 : URL publique stable, slug
 * unique, QR code (SVG + PNG), partage (natif, copie, réseaux sociaux),
 * et respect strict des permissions déjà en place (§17-19).
 */
class ProjectShareTest extends FunctionalTestCase
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

    public function testPublicProjectPageExposesQrCodeInTwoFormatsAndShareLinks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.qr@example.com', 'owner-qr');
        $this->makeProject($em, $owner, 'projet-qr-partage', ProjectStatus::PUBLIE);
        $em->flush();

        $crawler = $client->request('GET', '/projets/projet-qr-partage');
        self::assertResponseIsSuccessful();

        // QR code : deux formats de téléchargement, avec un alt text
        // explicite (cahier §27 — accessibilité).
        $qrImg = $crawler->filter('#qr-code-projet img');
        self::assertCount(1, $qrImg);
        self::assertStringContainsString('data:image/svg+xml', $qrImg->attr('src'));
        self::assertStringContainsString('Projet projet-qr-partage', $qrImg->attr('alt'));

        $svgLink = $crawler->filter('#qr-code-projet a[download$=".svg"]');
        $pngLink = $crawler->filter('#qr-code-projet a[download$=".png"]');
        self::assertCount(1, $svgLink);
        self::assertCount(1, $pngLink);
        self::assertStringContainsString('data:image/svg+xml', $svgLink->attr('href'));
        self::assertStringContainsString('data:image/png', $pngLink->attr('href'));

        // Bouton « Partager » : attributs pour le partage natif, avec repli
        // vers la modale (cahier §12).
        $shareButton = $crawler->filter('[data-partage-natif]');
        self::assertCount(1, $shareButton);
        self::assertSame('modale-partage', $shareButton->attr('data-partage-repli'));
        self::assertStringContainsString('/projets/projet-qr-partage', (string) $shareButton->attr('data-partage-url'));

        // Modale de partage : réseaux sociaux (cahier §13/§14).
        $modale = $crawler->filter('#modale-partage');
        self::assertStringContainsString('wa.me', $modale->html());
        self::assertStringContainsString('linkedin.com', $modale->html());
        self::assertStringContainsString('facebook.com/sharer', $modale->html());
        self::assertStringContainsString('twitter.com/intent/tweet', $modale->html());
    }

    public function testMaskedProjectIsNotPubliclyAccessible(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.masque@example.com', 'owner-masque');
        $this->makeProject($em, $owner, 'projet-masque-qr', ProjectStatus::REJETE);
        $em->flush();

        $client->request('GET', '/projets/projet-masque-qr');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOpenGraphAndCanonicalUrlArePresentOnThePublicPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.og@example.com', 'owner-og');
        $this->makeProject($em, $owner, 'projet-og-test', ProjectStatus::PUBLIE);
        $em->flush();

        $crawler = $client->request('GET', '/projets/projet-og-test');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('meta[property="og:title"]'));
        self::assertCount(1, $crawler->filter('meta[property="og:description"]'));
        self::assertCount(1, $crawler->filter('meta[property="og:url"]'));
        self::assertCount(1, $crawler->filter('link[rel="canonical"][href$="/projets/projet-og-test"]'));
    }

    public function testDuplicateProjectNamesGetDistinctIncrementalSlugs(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.doublon@example.com', 'owner-doublon');
        $em->flush();

        $client->loginUser($owner);

        for ($i = 0; $i < 2; ++$i) {
            $crawler = $client->request('GET', '/publier');
            $form = $crawler->selectButton('Envoyer pour publication')->form([
                'publish_project[type]' => 'personnel',
                'publish_project[name]' => 'Assistant de révision par IA',
                'publish_project[githubUrl]' => 'https://github.com/owner/assistant-revision',
            ]);
            $client->submit($form);
            self::assertResponseIsSuccessful();
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $projects = $em->getRepository(Project::class)->findBy(['name' => 'Assistant de révision par IA'], ['id' => 'ASC']);
        self::assertCount(2, $projects);
        self::assertNotSame($projects[0]->getSlug(), $projects[1]->getSlug());
        self::assertSame($projects[0]->getSlug().'-2', $projects[1]->getSlug());
    }

    public function testSlugAndPublicUrlStayStableAfterEditingProjectContent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.stabilite@example.com', 'owner-stabilite');
        $project = $this->makeProject($em, $owner, 'projet-stabilite-qr', ProjectStatus::PUBLIE);
        $em->flush();
        $originalSlug = $project->getSlug();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/projet-stabilite-qr/modifier');
        $form = $crawler->selectButton('Enregistrer les modifications')->form([
            'publish_project[name]' => 'Nom totalement différent',
            'publish_project[detailedDescription]' => 'Description mise à jour après modification.',
            'publish_project[githubUrl]' => 'https://github.com/owner/projet-stabilite',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($project->getId());
        self::assertSame($originalSlug, $refreshed->getSlug(), 'Editing a project\'s content must never change its slug (cahier §4/§20) : the QR code must keep working.');
        self::assertSame('Nom totalement différent', $refreshed->getName());

        // L'ancienne URL (basée sur le slug stable, pas sur le nom) reste
        // valide et affiche désormais le contenu à jour.
        $client->request('GET', '/projets/'.$originalSlug);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nom totalement différent');
    }

    public function testAdminSeesSlugPublicUrlAndQrCodeForAPublishedProject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.qr@example.com', 'admin-qr', ['ROLE_ADMIN']);
        $owner = $this->makeUser($em, 'owner.admin-qr@example.com', 'owner-admin-qr');
        $project = $this->makeProject($em, $owner, 'projet-admin-qr', ProjectStatus::VERIFIE);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$project->getId());
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('body', 'projet-admin-qr');
        self::assertSelectorExists('input[value*="/projets/projet-admin-qr"]');
        $qrImg = $crawler->filter('img[alt*="projet-admin-qr"]');
        self::assertCount(1, $qrImg);
    }

    public function testAdminSeesUnavailableMessageInsteadOfQrCodeForAnUnpublishedProject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.qr2@example.com', 'admin-qr2', ['ROLE_ADMIN']);
        $owner = $this->makeUser($em, 'owner.admin-qr2@example.com', 'owner-admin-qr2');
        $project = $this->makeProject($em, $owner, 'projet-admin-qr2', ProjectStatus::EN_ATTENTE);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$project->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('img[alt*="projet-admin-qr2"]'));
        self::assertSelectorTextContains('body', 'indisponible');
    }

    /**
     * Complément du « problème restant » signalé au rapport F11 : le bloc
     * `og_url` ajouté à `base.html.twig` doit aussi être utilisé par les
     * autres pages publiques qui partagent déjà le même modèle de partage
     * (soutenance, établissement, profil talent), pas seulement par la
     * page projet.
     */
    public function testOgUrlIsPresentOnTheDefensePublicPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.og-defense@example.com', 'owner-og-defense');
        $project = $this->makeProject($em, $owner, 'projet-og-defense', ProjectStatus::PUBLIE);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate(new \DateTimeImmutable('2026-12-01'));
        $defense->setTime('09:00');
        $defense->setPlace('Amphi A');
        $defense->setStatus(DefenseStatus::ANNONCEE);
        $project->setDefense($defense);
        $em->persist($defense);
        $em->flush();

        $crawler = $client->request('GET', '/soutenances/projet-og-defense');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('meta[property="og:url"]'));
        self::assertStringContainsString('/soutenances/projet-og-defense', (string) $crawler->filter('meta[property="og:url"]')->attr('content'));
    }

    public function testOgUrlIsPresentOnTheInstitutionPublicPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = new Institution();
        $institution->setName('Institut OG Test');
        $institution->setSlug('institut-og-test');
        $institution->setType(InstitutionType::UNIVERSITE);
        $institution->setActive(true);
        $institution->setVerified(true);
        $em->persist($institution);
        $em->flush();

        $crawler = $client->request('GET', '/etablissements/institut-og-test');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('meta[property="og:url"]'));
        self::assertStringContainsString('/etablissements/institut-og-test', (string) $crawler->filter('meta[property="og:url"]')->attr('content'));
    }

    public function testOgUrlIsPresentOnTheTalentProfilePublicPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $this->makeUser($em, 'owner.og-profil@example.com', 'owner-og-profil');
        $em->flush();

        $crawler = $client->request('GET', '/profils/owner-og-profil');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('meta[property="og:url"]'));
        self::assertStringContainsString('/profils/owner-og-profil', (string) $crawler->filter('meta[property="og:url"]')->attr('content'));
    }
}
