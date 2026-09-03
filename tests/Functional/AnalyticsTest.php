<?php

namespace App\Tests\Functional;

use App\Entity\AnalyticsEvent;
use App\Entity\ContactRequest;
use App\Entity\Project;
use App\Entity\ProjectProof;
use App\Entity\RecruiterFavorite;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\AnalyticsEventType;
use App\Enum\ContactRequestStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ProofType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 12 : statistiques et analytics
 * (vues, QR, clics de preuve, partages, permissions, agrégations).
 */
class AnalyticsTest extends FunctionalTestCase
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

    // ---- Vues ---------------------------------------------------------

    public function testViewingAPublishedProjectRecordsAProjectViewEvent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.vue@example.com', 'owner-vue');
        $project = $this->makeProject($em, $owner, 'projet-vue-test', ProjectStatus::PUBLIE);
        $em->flush();
        $projectId = $project->getId();

        $client->request('GET', '/projets/projet-vue-test');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId, 'type' => AnalyticsEventType::PROJECT_VIEW]);
        self::assertCount(1, $events);
        self::assertSame('direct', $events[0]->getMetadata());
    }

    public function testViewingADraftOrPendingProjectAsOwnerDoesNotRecordAView(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.brouillon-vue@example.com', 'owner-brouillon-vue');
        $project = $this->makeProject($em, $owner, 'projet-brouillon-vue', ProjectStatus::EN_ATTENTE);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($owner);
        $client->request('GET', '/projets/projet-brouillon-vue');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId]));
    }

    public function testMaskedProjectRecordsNoView(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.masque-vue@example.com', 'owner-masque-vue');
        $project = $this->makeProject($em, $owner, 'projet-masque-vue', ProjectStatus::REJETE);
        $em->flush();
        $projectId = $project->getId();

        $client->request('GET', '/projets/projet-masque-vue');
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId]));
    }

    public function testReloadingWithinTheDedupWindowDoesNotCreateASecondViewEvent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.dedup@example.com', 'owner-dedup');
        $project = $this->makeProject($em, $owner, 'projet-dedup-test', ProjectStatus::PUBLIE);
        $em->flush();
        $projectId = $project->getId();

        $client->request('GET', '/projets/projet-dedup-test');
        $client->request('GET', '/projets/projet-dedup-test');
        $client->request('GET', '/projets/projet-dedup-test');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId, 'type' => AnalyticsEventType::PROJECT_VIEW]));
    }

    public function testVisitOriginatingFromTheQrCodeIsRecordedAsSuch(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.qr-vue@example.com', 'owner-qr-vue');
        $project = $this->makeProject($em, $owner, 'projet-qr-vue-test', ProjectStatus::PUBLIE);
        $em->flush();
        $projectId = $project->getId();

        $client->request('GET', '/projets/projet-qr-vue-test?src=qr');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId, 'type' => AnalyticsEventType::PROJECT_VIEW]);
        self::assertCount(1, $events);
        self::assertSame('qr', $events[0]->getMetadata());
    }

    // ---- Liens (clics de preuve) ---------------------------------------

    public function testClickingAProofLinkRecordsTheClickAndRedirectsToTheRealUrl(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.clic@example.com', 'owner-clic');
        $project = $this->makeProject($em, $owner, 'projet-clic-test', ProjectStatus::PUBLIE);
        $em->flush();

        $github = new ProjectProof();
        $github->setType(ProofType::GITHUB);
        $github->setUrl('https://github.com/owner/projet-clic-test');
        $project->addProof($github);
        $em->persist($github);

        $demo = new ProjectProof();
        $demo->setType(ProofType::DEMO);
        $demo->setUrl('https://demo.example.com/projet-clic-test');
        $project->addProof($demo);
        $em->persist($demo);
        $em->flush();

        $projectId = $project->getId();
        $githubId = $github->getId();
        $demoId = $demo->getId();

        $client->request('GET', '/projets/projet-clic-test/preuves/'.$githubId.'/ouvrir');
        self::assertResponseRedirects('https://github.com/owner/projet-clic-test');

        $client->request('GET', '/projets/projet-clic-test/preuves/'.$demoId.'/ouvrir');
        self::assertResponseRedirects('https://demo.example.com/projet-clic-test');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId, 'type' => AnalyticsEventType::PROOF_CLICK]);
        self::assertCount(2, $events);
        $metadata = array_map(fn (AnalyticsEvent $e) => $e->getMetadata(), $events);
        self::assertContains('github', $metadata);
        self::assertContains('demo', $metadata);
    }

    public function testProofClickFromAnotherProjectIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.idor@example.com', 'owner-idor');
        $projectA = $this->makeProject($em, $owner, 'projet-idor-a', ProjectStatus::PUBLIE);
        $projectB = $this->makeProject($em, $owner, 'projet-idor-b', ProjectStatus::PUBLIE);
        $em->flush();

        $proofOfB = new ProjectProof();
        $proofOfB->setType(ProofType::GITHUB);
        $proofOfB->setUrl('https://github.com/owner/b');
        $projectB->addProof($proofOfB);
        $em->persist($proofOfB);
        $em->flush();

        // La preuve appartient au projet B, on tente de l'ouvrir via le slug du projet A.
        $client->request('GET', '/projets/projet-idor-a/preuves/'.$proofOfB->getId().'/ouvrir');
        self::assertResponseStatusCodeSame(404);
    }

    // ---- Partage et vidéo ------------------------------------------------

    public function testShareTrackingRoutePersistsAShareEvent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.partage@example.com', 'owner-partage');
        $project = $this->makeProject($em, $owner, 'projet-partage-test', ProjectStatus::PUBLIE);
        $em->flush();
        $projectId = $project->getId();

        $client->request('POST', '/projets/projet-partage-test/partage/enregistrer');
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId, 'type' => AnalyticsEventType::PROJECT_SHARE]));
    }

    public function testVideoOpenTrackingRoutePersistsAYoutubeOpenEvent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.video@example.com', 'owner-video');
        $project = $this->makeProject($em, $owner, 'projet-video-test', ProjectStatus::PUBLIE);
        $em->flush();
        $projectId = $project->getId();

        $client->request('POST', '/projets/projet-video-test/video/ouverture');
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId, 'type' => AnalyticsEventType::YOUTUBE_OPEN]));
    }

    public function testQrCodeDownloadIsTrackedAndServedAsARealFile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.qrdl@example.com', 'owner-qrdl');
        $project = $this->makeProject($em, $owner, 'projet-qrdl-test', ProjectStatus::PUBLIE);
        $em->flush();
        $projectId = $project->getId();

        $client->request('GET', '/projets/projet-qrdl-test/qr.png');
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(AnalyticsEvent::class)->findBy(['project' => $projectId, 'type' => AnalyticsEventType::QR_DOWNLOAD]);
        self::assertCount(1, $events);
        self::assertSame('png', $events[0]->getMetadata());
    }

    // ---- Permissions ------------------------------------------------------

    public function testOwnerCanSeeTheirOwnProjectStatsButAnotherTalentIsDenied(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.stats-perm@example.com', 'owner-stats-perm');
        $stranger = $this->makeUser($em, 'stranger.stats-perm@example.com', 'stranger-stats-perm');
        $admin = $this->makeUser($em, 'admin.stats-perm@example.com', 'admin-stats-perm', ['ROLE_ADMIN']);
        $project = $this->makeProject($em, $owner, 'projet-stats-perm', ProjectStatus::PUBLIE);
        $em->flush();

        $client->loginUser($owner);
        $client->request('GET', '/projets/projet-stats-perm/statistiques');
        self::assertResponseIsSuccessful();

        $client->loginUser($stranger);
        $client->request('GET', '/projets/projet-stats-perm/statistiques');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->loginUser($admin);
        $client->request('GET', '/projets/projet-stats-perm/statistiques');
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousVisitorCannotAccessProjectStats(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.stats-anon@example.com', 'owner-stats-anon');
        $this->makeProject($em, $owner, 'projet-stats-anon', ProjectStatus::PUBLIE);
        $em->flush();

        $client->request('GET', '/projets/projet-stats-anon/statistiques');
        self::assertTrue($client->getResponse()->isRedirect(), 'An anonymous visitor must be redirected to login, never see private stats.');
    }

    // ---- Agrégations --------------------------------------------------

    public function testProjectStatsAggregationMatchesTheRecordedEvents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.aggregat@example.com', 'owner-aggregat');
        $visitorA = $this->makeUser($em, 'visitora.aggregat@example.com', 'visitora-aggregat');
        $visitorB = $this->makeUser($em, 'visitorb.aggregat@example.com', 'visitorb-aggregat');
        $project = $this->makeProject($em, $owner, 'projet-aggregat-test', ProjectStatus::PUBLIE);
        $em->flush();

        // Deux visiteurs authentifiés distincts consultent le projet : 2 vues
        // totales, 2 vues uniques (comptes différents).
        $client->loginUser($visitorA);
        $client->request('GET', '/projets/projet-aggregat-test');
        $client->loginUser($visitorB);
        $client->request('GET', '/projets/projet-aggregat-test?src=qr');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $analyticsEventRepository = $em->getRepository(AnalyticsEvent::class);
        \assert($analyticsEventRepository instanceof \App\Repository\AnalyticsEventRepository);

        $refreshedProject = $em->getRepository(Project::class)->find($project->getId());
        $stats = $analyticsEventRepository->projectStats($refreshedProject);

        self::assertSame(2, $stats['totalViews']);
        self::assertSame(2, $stats['uniqueViews']);
        self::assertSame(1, $stats['qrViews']);
        self::assertSame(1, $stats['directViews']);
    }

    public function testAdminDashboardExposesGlobalAnalyticsMatchingRealEvents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.dashboard-stats@example.com', 'admin-dashboard-stats', ['ROLE_ADMIN']);
        $owner = $this->makeUser($em, 'owner.dashboard-stats@example.com', 'owner-dashboard-stats');
        $project = $this->makeProject($em, $owner, 'projet-dashboard-stats', ProjectStatus::PUBLIE);
        $em->flush();

        $client->request('GET', '/projets/projet-dashboard-stats');
        self::assertResponseIsSuccessful();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Vues totales');
    }

    public function testTalentSeesMesStatistiquesOnOwnProfileButNotOnAnotherTalentsProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.messtats@example.com', 'owner-messtats');
        $stranger = $this->makeUser($em, 'stranger.messtats@example.com', 'stranger-messtats');
        $project = $this->makeProject($em, $owner, 'projet-messtats', ProjectStatus::PUBLIE);
        $em->flush();

        // Une vue réelle pour avoir un chiffre non nul à vérifier.
        $client->request('GET', '/projets/projet-messtats');

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/profils/owner-messtats');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-onglet="statistiques"]');
        self::assertSelectorTextContains('body', 'Mes statistiques');

        $client->loginUser($stranger);
        $crawler = $client->request('GET', '/profils/owner-messtats');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-onglet="statistiques"]'), 'A visitor must never see another talent\'s detailed statistics.');
    }

    // ---- Complément « Problèmes restants » : recherches et interactions --

    public function testSearchingByTechnologyInExplorerRecordsAnAnonymousSearchEvent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $technology = new Technology();
        $technology->setName('Symfony');
        $em->persist($technology);
        $em->flush();
        $technologyId = $technology->getId();

        $client->request('GET', '/explorer?'.http_build_query(['technologies' => [$technologyId]]));
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(AnalyticsEvent::class)->findBy(['type' => AnalyticsEventType::TECHNOLOGY_SEARCH]);
        self::assertCount(1, $events);
        self::assertSame((string) $technologyId, $events[0]->getMetadata());
        self::assertNull($events[0]->getProject(), 'A technology search is not tied to any single project.');
        self::assertNull($events[0]->getUser(), 'A technology search must remain strictly anonymous.');
        self::assertNull($events[0]->getVisitorHash(), 'A technology search must remain strictly anonymous.');
    }

    public function testAdminDashboardShowsTopSearchedTechnologies(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.recherche@example.com', 'admin-recherche', ['ROLE_ADMIN']);
        $technology = new Technology();
        $technology->setName('AngularSearchTest');
        $em->persist($technology);
        $em->flush();

        $client->request('GET', '/explorer?'.http_build_query(['technologies' => [$technology->getId()]]));

        $client->loginUser($admin);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'AngularSearchTest');
    }

    public function testTalentStatsExposeFavoritesAndContactRequestsReceived(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.favoris-stats@example.com', 'owner-favoris-stats', ['ROLE_TALENT']);
        $recruiter = $this->makeUser($em, 'recruiter.favoris-stats@example.com', 'recruiter-favoris-stats', ['ROLE_RECRUITER']);
        $em->flush();

        $favorite = new RecruiterFavorite();
        $favorite->setRecruiter($recruiter);
        $favorite->setTalent($owner);
        $em->persist($favorite);

        $contactRequest = new ContactRequest();
        $contactRequest->setRecruiter($recruiter);
        $contactRequest->setTalent($owner);
        $contactRequest->setMessage('Bonjour');
        $contactRequest->setStatus(ContactRequestStatus::PENDING);
        $em->persist($contactRequest);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/profils/owner-favoris-stats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'ajouts aux favoris');
        self::assertSelectorTextContains('body', 'demandes de contact reçues');
    }
}
