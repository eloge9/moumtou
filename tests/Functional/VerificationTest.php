<?php

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\ProjectProof;
use App\Entity\User;
use App\Entity\VerificationEvent;
use App\Entity\VerificationRequest;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ProofType;
use App\Enum\ReportTargetType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class VerificationTest extends FunctionalTestCase
{
    private function createUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_USER']): User
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

    private function createEligibleProject(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project();
        $project->setName('Application de gestion de stock');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setShortDescription('Une application de gestion de stock pour petites entreprises.');
        $project->setSlug($slug);
        $project->setOwner($owner);
        $proof = new ProjectProof();
        $proof->setType(ProofType::GITHUB);
        $proof->setUrl('https://github.com/example/stock');
        $project->addProof($proof);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testTalentCannotRequestVerificationWithoutProof(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'sansPreuve@example.com', 'sans-preuve', ['ROLE_TALENT']);
        $project = new Project();
        $project->setName('Projet sans preuve');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setShortDescription('Un projet publié sans aucune preuve fournie.');
        $project->setSlug('sans-preuve');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/sans-preuve');

        // Le bouton de demande est masqué côté talent tant que les
        // conditions ne sont pas réunies (cahier §7) : ce qui manque est
        // affiché clairement, et aucun formulaire de demande n'est rendu.
        self::assertCount(0, $crawler->filter('form[action="/projets/sans-preuve/verification/demander"]'));
        self::assertSelectorTextContains('.m-detail__cote', 'preuve');

        // Contournement de l'UI : un client qui construit la requête
        // directement sans jeton CSRF valide est rejeté côté serveur
        // (cahier §26/§27 — jamais uniquement une protection visuelle).
        // Comme partout ailleurs dans MOUMTOU, un jeton CSRF invalide est
        // traité comme une erreur d'authentification par Symfony (redirection).
        $client->request('POST', '/projets/sans-preuve/verification/demander', [
            '_csrf_token' => 'jeton-invalide',
        ]);
        self::assertResponseRedirects('/connexion');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $projectId]));
    }

    public function testFullApprovalFlowVerifiesTheProjectAndNotifiesTheOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'porteur@example.com', 'porteur', ['ROLE_TALENT']);
        $admin = $this->createUser($em, 'admin-verif@example.com', 'admin-verif', ['ROLE_ADMIN']);
        $project = $this->createEligibleProject($em, $owner, 'stock-verif');
        $projectId = $project->getId();

        // Le talent demande la vérification.
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/stock-verif');
        $form = $crawler->filter('form[action="/projets/stock-verif/verification/demander"]')->form();
        $client->submit($form);
        self::assertResponseRedirects('/projets/stock-verif');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $projectId]);
        self::assertNotNull($request);
        self::assertSame('en_attente', $request->getStatus()->value);
        $requestId = $request->getId();

        // L'admin prend en charge puis valide.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/prendre-en-charge"]')->form();
        $client->submit($form);

        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/valider"]')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/verifications/'.$requestId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        $request = $em->getRepository(VerificationRequest::class)->find($requestId);

        self::assertSame('verifie', $project->getStatus()->value);
        self::assertNotNull($project->getVerifiedAt());
        self::assertSame($admin->getId(), $project->getVerifiedBy()->getId());
        self::assertSame('verifiee', $request->getStatus()->value);

        $notification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $owner, 'type' => \App\Enum\NotificationType::PROJECT_VERIFIED]);
        self::assertNotNull($notification);

        // Badge visible publiquement.
        $client->request('GET', '/projets/stock-verif');
        self::assertSelectorTextContains('.m-badge--verifie', 'Projet vérifié');
    }

    public function testCorrectionRequiresReasonAndResubmitReturnsToPending(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'correction@example.com', 'correction-owner', ['ROLE_TALENT']);
        $admin = $this->createUser($em, 'admin-correction@example.com', 'admin-correction', ['ROLE_ADMIN']);
        $project = $this->createEligibleProject($em, $owner, 'stock-correction');
        $projectId = $project->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/stock-correction');
        $client->submit($crawler->filter('form[action="/projets/stock-correction/verification/demander"]')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $projectId]);
        $requestId = $request->getId();

        // Motif obligatoire.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/corriger"]')->form(['reason' => '']);
        $client->submit($form);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->find($requestId);
        self::assertSame('en_attente', $request->getStatus()->value);

        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/corriger"]')->form([
            'reason' => 'Le lien GitHub fourni ne correspond pas au projet présenté.',
        ]);
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->find($requestId);
        self::assertSame('correction_demandee', $request->getStatus()->value);
        self::assertSame('Le lien GitHub fourni ne correspond pas au projet présenté.', $request->getReason());

        // Le talent voit le motif et resoumet.
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/stock-correction');
        self::assertSelectorTextContains('.m-detail__cote', 'Le lien GitHub fourni ne correspond pas au projet présenté.');
        $form = $crawler->filter('form[action="/projets/stock-correction/verification/resoumettre"]')->form();
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->find($requestId);
        self::assertSame('en_attente', $request->getStatus()->value);

        // Historique conservé (demande, correction, resoumission).
        $events = $em->getRepository(VerificationEvent::class)->findBy(['request' => $request]);
        self::assertGreaterThanOrEqual(3, \count($events));
    }

    public function testRejectionRequiresReasonAndKeepsProjectPublic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'refus@example.com', 'refus-owner', ['ROLE_TALENT']);
        $admin = $this->createUser($em, 'admin-refus@example.com', 'admin-refus', ['ROLE_ADMIN']);
        $project = $this->createEligibleProject($em, $owner, 'stock-refus');
        $projectId = $project->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/stock-refus');
        $client->submit($crawler->filter('form[action="/projets/stock-refus/verification/demander"]')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $projectId]);
        $requestId = $request->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/refuser"]')->form([
            'reason' => 'Preuves insuffisantes.',
        ]);
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        $request = $em->getRepository(VerificationRequest::class)->find($requestId);

        self::assertSame('refusee', $request->getStatus()->value);
        // Un projet refusé reste public : publication ≠ vérification (cahier §2).
        self::assertSame('publie', $project->getStatus()->value);

        $notification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $owner, 'type' => \App\Enum\NotificationType::PROJECT_VERIFICATION_REFUSED]);
        self::assertNotNull($notification);
    }

    public function testRevokeOnlyAllowedOnAVerifiedRequestAndProjectReturnsToPublished(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'retrait@example.com', 'retrait-owner', ['ROLE_TALENT']);
        $admin = $this->createUser($em, 'admin-retrait@example.com', 'admin-retrait', ['ROLE_ADMIN']);
        $project = $this->createEligibleProject($em, $owner, 'stock-retrait');
        $projectId = $project->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/stock-retrait');
        $client->submit($crawler->filter('form[action="/projets/stock-retrait/verification/demander"]')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $projectId]);
        $requestId = $request->getId();

        $client->loginUser($admin);

        // Retrait impossible tant que ce n'est pas vérifié : le bouton n'existe pas.
        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        self::assertCount(0, $crawler->filter('form[action="/admin/verifications/'.$requestId.'/retirer"]'));

        $client->submit($crawler->filter('form[action="/admin/verifications/'.$requestId.'/valider"]')->form());

        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/retirer"]')->form([
            'reason' => 'Le dépôt GitHub a été supprimé depuis.',
        ]);
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        $request = $em->getRepository(VerificationRequest::class)->find($requestId);

        self::assertSame('retiree', $request->getStatus()->value);
        self::assertSame('publie', $project->getStatus()->value);
        self::assertNull($project->getVerifiedAt());

        // Historique conservé malgré le retrait.
        $events = $em->getRepository(VerificationEvent::class)->findBy(['request' => $request]);
        self::assertGreaterThanOrEqual(3, \count($events));
    }

    public function testTalentCannotAccessAdminVerificationEndpoints(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'intrus@example.com', 'intrus', ['ROLE_TALENT']);
        $project = $this->createEligibleProject($em, $owner, 'stock-intrus');

        $client->loginUser($owner);
        $client->request('GET', '/projets/stock-intrus');
        $client->submit($client->getCrawler()->filter('form[action="/projets/stock-intrus/verification/demander"]')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $project->getId()]);

        $client->request('GET', '/admin/verifications');
        self::assertResponseStatusCodeSame(403);

        // #[IsGranted('ROLE_ADMIN')] est vérifié avant tout traitement de la
        // requête (y compris le contrôle CSRF) : un jeton absent n'a aucune
        // incidence sur ce test, seul le rôle compte ici.
        $client->request('POST', '/admin/verifications/'.$request->getId().'/valider', [
            '_csrf_token' => 'invalide',
        ]);
        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($project->getId());
        self::assertSame('publie', $project->getStatus()->value, 'Un talent ne doit jamais pouvoir forger son propre statut vérifié.');
    }

    public function testExistingQuickModerationActionStaysInSyncWithAnOpenRequest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'sync@example.com', 'sync-owner', ['ROLE_TALENT']);
        $admin = $this->createUser($em, 'admin-sync@example.com', 'admin-sync', ['ROLE_ADMIN']);
        $project = $this->createEligibleProject($em, $owner, 'stock-sync');
        $projectId = $project->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/stock-sync');
        $client->submit($crawler->filter('form[action="/projets/stock-sync/verification/demander"]')->form());

        // L'admin utilise le raccourci de modération déjà existant sur la fiche projet,
        // pas le nouvel espace "Vérifications" — comportement préexistant à préserver.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$projectId);
        $form = $crawler->filter('form[action="/admin/moderation/projets/'.$projectId.'/decider"]')->selectButton('✅ Marquer comme vérifié')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/projets/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        self::assertSame('verifie', $project->getStatus()->value);

        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $projectId]);
        self::assertSame('verifiee', $request->getStatus()->value, 'La demande formelle doit rester synchronisée avec le raccourci de modération.');
    }

    public function testProfileVerificationRequestAndApprovalGrantsTheBadge(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'profil@example.com', 'profil-talent', ['ROLE_TALENT']);
        $talent->setBio('Développeuse full-stack passionnée par les systèmes embarqués.');
        $talent->setLinkedinUrl('https://linkedin.com/in/example');
        $admin = $this->createUser($em, 'admin-profil@example.com', 'admin-profil', ['ROLE_ADMIN']);
        $em->flush();
        $talentId = $talent->getId();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->filter('form[action="/mon-profil/verification/demander"]')->form();
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROFILE, 'targetId' => $talentId]);
        self::assertNotNull($request);
        $requestId = $request->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/valider"]')->form();
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $talent = $em->getRepository(User::class)->find($talentId);
        self::assertTrue($talent->isProfileVerified());
        self::assertNotNull($talent->getProfileVerifiedAt());

        $client->request('GET', '/profils/profil-talent');
        self::assertSelectorTextContains('.m-badge--verifie', 'Profil vérifié');
    }

    public function testVerificationQueueFiltersAndPagination(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin-queue@example.com', 'admin-queue', ['ROLE_ADMIN']);
        $owner = $this->createUser($em, 'queue-owner@example.com', 'queue-owner', ['ROLE_TALENT']);
        $this->createEligibleProject($em, $owner, 'stock-queue');
        $em->flush();

        $client->loginUser($owner);
        $client->request('GET', '/projets/stock-queue');
        $client->submit($client->getCrawler()->filter('form[action="/projets/stock-queue/verification/demander"]')->form());

        $client->loginUser($admin);
        $client->request('GET', '/admin/verifications?type=project&status=en_attente&page=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'stock');
    }

    public function testAdminCanMarkAProofAsReviewedDuringExamination(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'preuve@example.com', 'preuve-owner', ['ROLE_TALENT']);
        $admin = $this->createUser($em, 'admin-preuve@example.com', 'admin-preuve', ['ROLE_ADMIN']);
        $project = $this->createEligibleProject($em, $owner, 'stock-preuve');
        $proof = $project->getProofs()->first();
        self::assertFalse($proof->isReviewed());

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/stock-preuve');
        $client->submit($crawler->filter('form[action="/projets/stock-preuve/verification/demander"]')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(VerificationRequest::class)->findOneBy(['targetType' => ReportTargetType::PROJECT, 'targetId' => $project->getId()]);
        $requestId = $request->getId();
        $proofId = $proof->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/preuves/'.$proofId.'/basculer"]')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/verifications/'.$requestId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $proof = $em->getRepository(ProjectProof::class)->find($proofId);
        self::assertTrue($proof->isReviewed());

        // Bascule à nouveau : décochée.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/verifications/'.$requestId);
        $form = $crawler->filter('form[action="/admin/verifications/'.$requestId.'/preuves/'.$proofId.'/basculer"]')->form();
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $proof = $em->getRepository(ProjectProof::class)->find($proofId);
        self::assertFalse($proof->isReviewed());
    }

    public function testVerifiedBadgeTextAppearsInMetaDescriptionOnceVerified(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'seo@example.com', 'seo-owner', ['ROLE_TALENT']);
        $project = $this->createEligibleProject($em, $owner, 'stock-seo');
        $project->setStatus(ProjectStatus::VERIFIE);
        $project->setVerifiedAt(new \DateTimeImmutable());
        $em->flush();

        $crawler = $client->request('GET', '/projets/stock-seo');
        $description = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString('Projet vérifié par MOUMTOU', $description);
        $ogDescription = $crawler->filter('meta[property="og:description"]')->attr('content');
        self::assertStringContainsString('Projet vérifié par MOUMTOU', $ogDescription);
    }
}
