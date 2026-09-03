<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Entity\ProjectPhoto;
use App\Entity\ProjectProof;
use App\Entity\User;
use App\Enum\ProjectDocumentType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ProofType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 10 : gestion des médias/preuves des
 * projets (galerie post-publication, démo/site distincts, autre preuve,
 * documents, extraction YouTube).
 */
class ProjectMediaTest extends FunctionalTestCase
{
    private function createUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT']): User
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

    private function createProjectWithTwoPhotos(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project();
        $project->setName('Projet média '.$slug);
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);

        $photo1 = new ProjectPhoto();
        $photo1->setPath('uploads/projects/test/photo1.jpg');
        $photo1->setPosition(0);
        $project->addPhoto($photo1);
        $em->persist($photo1);

        $photo2 = new ProjectPhoto();
        $photo2->setPath('uploads/projects/test/photo2.jpg');
        $photo2->setPosition(1);
        $project->addPhoto($photo2);
        $em->persist($photo2);

        $em->flush();

        return $project;
    }

    public function testOwnerCanSetCoverReorderAndDeletePhotosButAnotherUserCannot(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.media@example.com', 'owner-media');
        $stranger = $this->createUser($em, 'stranger.media@example.com', 'stranger-media');
        $project = $this->createProjectWithTwoPhotos($em, $owner, 'projet-media-photos');
        $projectId = $project->getId();
        $slug = $project->getSlug();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $photos = $em->getRepository(ProjectPhoto::class)->findBy(['project' => $projectId], ['position' => 'ASC']);
        $firstPhotoId = $photos[0]->getId();
        $secondPhotoId = $photos[1]->getId();

        // Un autre utilisateur ne doit pas pouvoir définir l'image principale.
        $client->loginUser($stranger);
        $client->request('POST', '/projets/'.$slug.'/photos/'.$secondPhotoId.'/principale', [
            '_csrf_token' => 'invalide',
        ]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        // Le propriétaire peut définir la 2e photo comme principale.
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/'.$slug.'/modifier');
        $token = $crawler->filter('form[action="/projets/'.$slug.'/photos/'.$secondPhotoId.'/principale"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/'.$slug.'/photos/'.$secondPhotoId.'/principale', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/projets/'.$slug.'/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshedFirst = $em->getRepository(ProjectPhoto::class)->find($firstPhotoId);
        $refreshedSecond = $em->getRepository(ProjectPhoto::class)->find($secondPhotoId);
        self::assertSame(0, $refreshedSecond->getPosition());
        self::assertSame(1, $refreshedFirst->getPosition());

        // Suppression d'une photo par le propriétaire.
        $crawler = $client->request('GET', '/projets/'.$slug.'/modifier');
        $token = $crawler->filter('form[action="/projets/'.$slug.'/photos/'.$firstPhotoId.'/supprimer"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/'.$slug.'/photos/'.$firstPhotoId.'/supprimer', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/projets/'.$slug.'/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(ProjectPhoto::class)->find($firstPhotoId));
        self::assertCount(1, $em->getRepository(ProjectPhoto::class)->findBy(['project' => $projectId]));
    }

    public function testOwnerCanReplaceAPhotoKeepingItsPosition(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.remplace@example.com', 'owner-remplace');
        $project = $this->createProjectWithTwoPhotos($em, $owner, 'projet-media-remplace');
        $slug = $project->getSlug();
        $projectId = $project->getId();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $coverPhoto = $em->getRepository(ProjectPhoto::class)->findOneBy(['project' => $projectId, 'position' => 0]);
        $coverPhotoId = $coverPhoto->getId();
        $originalPath = $coverPhoto->getPath();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/'.$slug.'/modifier');
        $token = $crawler->filter('form[action="/projets/'.$slug.'/photos/'.$coverPhotoId.'/remplacer"] input[name="_csrf_token"]')->attr('value');

        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $sourceImage = sys_get_temp_dir().'/moumtou-test-photo-remplacement.png';
        file_put_contents($sourceImage, $pngBytes);
        $uploadedFile = new UploadedFile($sourceImage, 'nouvelle-photo.png', 'image/png', null, true);

        $client->request('POST', '/projets/'.$slug.'/photos/'.$coverPhotoId.'/remplacer', ['_csrf_token' => $token], ['file' => $uploadedFile]);
        self::assertResponseRedirects('/projets/'.$slug.'/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(ProjectPhoto::class)->find($coverPhotoId);
        self::assertSame(0, $refreshed->getPosition(), 'Replacing a photo must keep its original position (and cover status).');
        self::assertNotSame($originalPath, $refreshed->getPath());

        $newAbsolutePath = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$refreshed->getPath();
        self::assertFileExists($newAbsolutePath);
        @unlink($newAbsolutePath);
        if ($refreshed->getThumbnailPath()) {
            @unlink(static::getContainer()->getParameter('kernel.project_dir').'/public/'.$refreshed->getThumbnailPath());
        }
    }

    public function testWizardPersistsDemoSiteAndOtherProofAsDistinctEntries(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.preuves@example.com', 'owner-preuves');
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'personnel',
            'publish_project[name]' => 'Application de suivi budgétaire',
            'publish_project[siteUrl]' => 'https://mon-site.example.com',
            'publish_project[demoUrl]' => 'https://ma-demo.example.com',
            'publish_project[otherProofTitle]' => 'Page produit',
            'publish_project[otherProofUrl]' => 'https://autre-preuve.example.com',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();

        $project = $em->getRepository(Project::class)->findOneBy(['name' => 'Application de suivi budgétaire']);
        self::assertNotNull($project);

        $proofsByType = [];
        foreach ($project->getProofs() as $proof) {
            $proofsByType[$proof->getType()->value] = $proof;
        }

        self::assertArrayHasKey('site', $proofsByType);
        self::assertSame('https://mon-site.example.com', $proofsByType['site']->getUrl());
        self::assertArrayHasKey('demo', $proofsByType);
        self::assertSame('https://ma-demo.example.com', $proofsByType['demo']->getUrl());
        self::assertArrayHasKey('autre', $proofsByType);
        self::assertSame('https://autre-preuve.example.com', $proofsByType['autre']->getUrl());
        self::assertSame('Page produit', $proofsByType['autre']->getTitle());
    }

    public function testDocumentDeletionRespectsPermissionsAndAdminCanViewIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.document@example.com', 'owner-document');
        $stranger = $this->createUser($em, 'stranger.document@example.com', 'stranger-document');
        $admin = $this->createUser($em, 'admin.document@example.com', 'admin-document', ['ROLE_ADMIN']);

        $project = new Project();
        $project->setName('Projet avec document');
        $project->setType(ProjectType::RECHERCHE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-document-test');
        $project->setOwner($owner);
        $em->persist($project);

        $document = new ProjectDocument();
        $document->setType(ProjectDocumentType::RAPPORT);
        $document->setTitle('Rapport final');
        $document->setPath('uploads/projects/test/documents/rapport.pdf');
        $document->setOriginalFilename('rapport.pdf');
        $document->setSize(1024);
        $project->addDocument($document);
        $em->persist($document);
        $em->flush();
        $slug = $project->getSlug();
        $documentId = $document->getId();

        // Un autre utilisateur ne peut pas supprimer le document.
        $client->loginUser($stranger);
        $client->request('POST', '/projets/'.$slug.'/documents/'.$documentId.'/supprimer', ['_csrf_token' => 'invalide']);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($em->getRepository(ProjectDocument::class)->find($documentId));

        // L'administrateur peut consulter la page projet (modération) sans erreur.
        $client->loginUser($admin);
        $client->request('GET', '/admin/projets/'.$project->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Rapport final');

        // Le propriétaire peut supprimer son document.
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/'.$slug.'/modifier');
        $token = $crawler->filter('form[action="/projets/'.$slug.'/documents/'.$documentId.'/supprimer"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/projets/'.$slug.'/documents/'.$documentId.'/supprimer', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/projets/'.$slug.'/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(ProjectDocument::class)->find($documentId));
    }

    public function testDocumentUploadedThroughTheEditWizardIsStoredWithItsType(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.upload-document@example.com', 'owner-upload-document');
        $project = new Project();
        $project->setName('Projet pour document réel');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-document-reel-test');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();
        $slug = $project->getSlug();
        $projectId = $project->getId();

        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF";
        $sourceFile = sys_get_temp_dir().'/moumtou-test-rapport.pdf';
        file_put_contents($sourceFile, $pdfContent);
        $uploadedFile = new UploadedFile($sourceFile, 'rapport-de-soutenance.pdf', 'application/pdf', null, true);

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/'.$slug.'/modifier');
        $form = $crawler->selectButton('Enregistrer les modifications')->form([
            'publish_project[githubUrl]' => 'https://github.com/owner/projet-document-reel',
            'publish_project[documentType]' => 'rapport',
            'publish_project[documentTitle]' => 'Rapport de soutenance',
        ]);

        // Le champ « documents » accepte plusieurs fichiers (attribut HTML
        // `multiple`) : DomCrawler ne sait envoyer qu'un seul fichier par
        // champ via Form::upload(), on passe donc directement le fichier
        // dans le tableau `$files` de la requête, en conservant le reste
        // des valeurs déjà résolues par le crawler (cf. InstitutionExtrasTest).
        $client->request(
            $form->getMethod(),
            $form->getUri(),
            $form->getPhpValues(),
            ['publish_project' => ['documents' => [$uploadedFile]]],
        );

        self::assertResponseRedirects('/projets/'.$slug);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $documents = $em->getRepository(ProjectDocument::class)->findBy(['project' => $projectId]);
        self::assertCount(1, $documents);
        self::assertSame(ProjectDocumentType::RAPPORT, $documents[0]->getType());
        self::assertSame('Rapport de soutenance', $documents[0]->getTitle());

        $absolutePath = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$documents[0]->getPath();
        self::assertFileExists($absolutePath);
        @unlink($absolutePath);
    }

    public function testPublicProjectPageDetectsYoutubeShortsUrl(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.shorts@example.com', 'owner-shorts');

        $project = new Project();
        $project->setName('Projet avec short YouTube');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-shorts-test');
        $project->setOwner($owner);
        $em->persist($project);

        $proof = new ProjectProof();
        $proof->setType(ProofType::YOUTUBE);
        $proof->setUrl('https://www.youtube.com/shorts/dQw4w9WgXcQ');
        $project->addProof($proof);
        $em->persist($proof);
        $em->flush();

        $client->request('GET', '/projets/projet-shorts-test');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-youtube-facade][data-video-id="dQw4w9WgXcQ"]');
    }

    public function testPublicProjectPageShowsDistinctProofButtonsAndDocuments(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.affichage@example.com', 'owner-affichage');

        $project = new Project();
        $project->setName('Projet avec toutes les preuves');
        $project->setType(ProjectType::ENTREPRENEURIAL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-toutes-preuves');
        $project->setOwner($owner);
        $em->persist($project);

        $demo = new ProjectProof();
        $demo->setType(ProofType::DEMO);
        $demo->setUrl('https://demo.example.com');
        $project->addProof($demo);
        $em->persist($demo);

        $document = new ProjectDocument();
        $document->setType(ProjectDocumentType::PRESENTATION);
        $document->setTitle('Présentation du projet');
        $document->setPath('uploads/projects/test/documents/presentation.pdf');
        $document->setOriginalFilename('presentation.pdf');
        $document->setSize(2048);
        $project->addDocument($document);
        $em->persist($document);

        $em->flush();

        $client->request('GET', '/projets/projet-toutes-preuves');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Voir la démo');
        self::assertSelectorTextContains('body', 'Présentation du projet');
    }

    /**
     * Cahier des charges §22 : la vignette de couverture affichée sur une
     * carte de projet (Explorer, Recherche, profil, établissement) ne doit
     * pas déclencher une requête par projet. {@see ProjectPhotoRepository::findCoversForProjects()}
     * doit renvoyer, en un seul appel, exactement la photo de plus petite
     * position par projet — jamais une autre, jamais celle d'un projet non
     * demandé.
     */
    public function testFindCoversForProjectsReturnsOnlyTheLowestPositionPhotoPerProject(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.covers@example.com', 'owner-covers');

        $projectWithPhotos = $this->createProjectWithTwoPhotos($em, $owner, 'projet-covers-avec-photos');

        $projectWithoutPhotos = new Project();
        $projectWithoutPhotos->setName('Projet sans photo');
        $projectWithoutPhotos->setType(ProjectType::PERSONNEL);
        $projectWithoutPhotos->setStatus(ProjectStatus::PUBLIE);
        $projectWithoutPhotos->setSlug('projet-covers-sans-photo');
        $projectWithoutPhotos->setOwner($owner);
        $em->persist($projectWithoutPhotos);
        $em->flush();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = $em->getRepository(ProjectPhoto::class);
        \assert($repository instanceof \App\Repository\ProjectPhotoRepository);

        $covers = $repository->findCoversForProjects([$projectWithPhotos, $projectWithoutPhotos]);

        self::assertArrayHasKey($projectWithPhotos->getId(), $covers);
        self::assertArrayNotHasKey($projectWithoutPhotos->getId(), $covers);
        self::assertSame('uploads/projects/test/photo1.jpg', $covers[$projectWithPhotos->getId()]->getPath());
    }

    public function testExplorerPageRendersCoverPhotoWithoutError(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.explorer-cover@example.com', 'owner-explorer-cover');
        $this->createProjectWithTwoPhotos($em, $owner, 'projet-explorer-cover');

        $client->request('GET', '/explorer');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[src*="photo1.jpg"]');
    }
}
