<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\ProjectPhoto;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gestion des projets : la règle centrale de cette tâche est que
 * PUBLICATION et VÉRIFICATION sont deux statuts distincts — un projet
 * publié est visible publiquement sans être automatiquement vérifié.
 */
class ProjectManagementTest extends FunctionalTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email, array $roles, string $slug): User
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

    // ---- Publication ≠ vérification --------------------------------------

    public function testPublishedProjectIsVisibleButShowsNotVerifiedIndicator(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.publie@example.com', ['ROLE_TALENT'], 'owner-publie');
        $this->makeProject($em, $owner, 'projet-publie-non-verifie', ProjectStatus::PUBLIE);
        $em->flush();

        $client->request('GET', '/projets/projet-publie-non-verifie');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Projet publié');
        self::assertSelectorTextContains('body', 'Pas encore vérifié');
        self::assertSelectorTextNotContains('body', 'Projet vérifié');
    }

    public function testVerifiedProjectShowsVerifiedBadgeInstead(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.verifie@example.com', ['ROLE_TALENT'], 'owner-verifie');
        $this->makeProject($em, $owner, 'projet-verifie-test', ProjectStatus::VERIFIE);
        $em->flush();

        $client->request('GET', '/projets/projet-verifie-test');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Projet vérifié');
        self::assertSelectorTextNotContains('body', 'Pas encore vérifié');
    }

    public function testDraftAndPendingProjectsAreNotPubliclyVisible(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.brouillon@example.com', ['ROLE_TALENT'], 'owner-brouillon');
        $this->makeProject($em, $owner, 'projet-brouillon', ProjectStatus::BROUILLON);
        $this->makeProject($em, $owner, 'projet-en-attente', ProjectStatus::EN_ATTENTE);
        $em->flush();

        $client->request('GET', '/projets/projet-brouillon');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/projets/projet-en-attente');
        self::assertResponseStatusCodeSame(404);
    }

    // ---- Modération admin --------------------------------------------------

    public function testAdminCanReviewProofsAndVerifyAProject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.verify@example.com', ['ROLE_ADMIN'], 'admin-verify');
        $owner = $this->makeUser($em, 'owner.averifier@example.com', ['ROLE_TALENT'], 'owner-averifier');
        $project = $this->makeProject($em, $owner, 'projet-a-verifier', ProjectStatus::PUBLIE);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$projectId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Projet '.'projet-a-verifier');

        $token = $crawler->filter('form[action="/admin/moderation/projets/'.$projectId.'/decider"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/moderation/projets/'.$projectId.'/decider', [
            'action' => 'marquer_verifie',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/projets/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(ProjectStatus::VERIFIE, $em->getRepository(Project::class)->find($projectId)->getStatus());
    }

    public function testAdminCanRemoveVerificationWithoutHidingTheProject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.unverify@example.com', ['ROLE_ADMIN'], 'admin-unverify');
        $owner = $this->makeUser($em, 'owner.averifier2@example.com', ['ROLE_TALENT'], 'owner-averifier2');
        $project = $this->makeProject($em, $owner, 'projet-verifie-a-retirer', ProjectStatus::VERIFIE);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$projectId);
        $token = $crawler->filter('form[action="/admin/moderation/projets/'.$projectId.'/decider"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/moderation/projets/'.$projectId.'/decider', [
            'action' => 'retirer_verification',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/projets/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($projectId);
        self::assertSame(ProjectStatus::PUBLIE, $refreshed->getStatus());

        // Toujours visible publiquement : la vérification n'est pas une condition de visibilité.
        $client->request('GET', '/projets/projet-verifie-a-retirer');
        self::assertResponseIsSuccessful();
    }

    public function testRequestingCorrectionWithoutReasonIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.correction@example.com', ['ROLE_ADMIN'], 'admin-correction');
        $owner = $this->makeUser($em, 'owner.correction@example.com', ['ROLE_TALENT'], 'owner-correction');
        $project = $this->makeProject($em, $owner, 'projet-a-corriger', ProjectStatus::EN_ATTENTE);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$projectId);
        $token = $crawler->filter('form[action="/admin/moderation/projets/'.$projectId.'/decider"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/moderation/projets/'.$projectId.'/decider', [
            'action' => 'demander_correction',
            'reason' => '',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/projets/'.$projectId);
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'Précisez le motif');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(ProjectStatus::EN_ATTENTE, $em->getRepository(Project::class)->find($projectId)->getStatus(), 'Le statut ne doit pas changer sans motif.');
    }

    public function testNonAdminCannotAccessProjectModerationPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.noadmin.proj@example.com', ['ROLE_TALENT'], 'talent-noadmin-proj');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/admin/projets');
        self::assertResponseStatusCodeSame(403);
    }

    // ---- Édition : un projet vérifié redevient "publié, non vérifié" ------

    public function testEditingAVerifiedProjectDowngradesItToPublishedNotVerified(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.edit.verified@example.com', ['ROLE_TALENT'], 'owner-edit-verified');
        $project = $this->makeProject($em, $owner, 'projet-edite-apres-verif', ProjectStatus::VERIFIE);
        $project->setDetailedDescription('Description initiale.');
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/projet-edite-apres-verif/modifier');
        $form = $crawler->selectButton('Enregistrer les modifications')->form([
            'publish_project[type]' => 'personnel',
            'publish_project[name]' => 'Projet edite-apres-verif',
            'publish_project[detailedDescription]' => 'Description substantiellement modifiée.',
            'publish_project[githubUrl]' => 'https://github.com/owner/projet',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($project->getId());
        self::assertSame(ProjectStatus::PUBLIE, $refreshed->getStatus(), 'Une modification substantielle doit faire perdre le statut vérifié.');
    }

    // ---- Photos : suppression sur édition ----------------------------------

    public function testOwnerCanRemoveAnExistingPhotoWhenEditing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.photo.remove@example.com', ['ROLE_TALENT'], 'owner-photo-remove');
        $project = $this->makeProject($em, $owner, 'projet-photo-a-retirer', ProjectStatus::PUBLIE);
        $em->flush();

        $uploadDir = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/projects/'.$project->getId();
        @mkdir($uploadDir, 0775, true);
        file_put_contents($uploadDir.'/existante.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        $photo = new ProjectPhoto();
        $photo->setPath(sprintf('uploads/projects/%d/existante.png', $project->getId()));
        $photo->setPosition(0);
        $project->addPhoto($photo);
        $em->persist($photo);
        $em->flush();
        $photoId = $photo->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/projet-photo-a-retirer/modifier');
        $deleteFormSelector = 'form[action="/projets/projet-photo-a-retirer/photos/'.$photoId.'/supprimer"]';
        self::assertSelectorExists($deleteFormSelector);

        $token = $crawler->filter($deleteFormSelector.' input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/projet-photo-a-retirer/photos/'.$photoId.'/supprimer', ['_csrf_token' => $token]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($project->getId());
        self::assertCount(0, $refreshed->getPhotos());
        self::assertNull($em->getRepository(ProjectPhoto::class)->find($photoId));
        self::assertFileDoesNotExist($uploadDir.'/existante.png');
    }
}
