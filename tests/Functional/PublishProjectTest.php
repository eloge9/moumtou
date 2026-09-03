<?php

namespace App\Tests\Functional;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Project;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PublishProjectTest extends FunctionalTestCase
{
    private function createLoggedInClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('talent@example.com')
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setPhone('+22890000000')
            ->setRoles(['ROLE_TALENT'])
            ->setStatus(UserStatus::ACTIF)
            ->setSlug('jean-dupont')
            ->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $client;
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/publier');

        self::assertResponseRedirects('/connexion');
    }

    public function testSubmittingWithoutProofIsRejected(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'personnel',
            'publish_project[name]' => 'Projet sans preuve',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Au moins une preuve de réalisation est obligatoire');
        self::assertCount(0, $em->getRepository(Project::class)->findAll());
    }

    public function testSoutenanceRequiresClassification(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'soutenance',
            'publish_project[name]' => 'Plateforme de gestion des étudiants',
            'publish_project[githubUrl]' => 'https://github.com/jdupont/scolarite',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'doit être classé');
        self::assertCount(0, $em->getRepository(Project::class)->findAll());
    }

    public function testValidSubmissionCreatesProjectWithProofsAndTechnologies(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $domain = new Domain();
        $domain->setName('Sciences et Technologies');
        $mention = new Mention();
        $mention->setName('Informatique');
        $mention->setDomain($domain);
        $specialty = new Specialty();
        $specialty->setName('Génie Logiciel');
        $specialty->setMention($mention);
        $institution = new Institution();
        $institution->setName('Université de Lomé')->setCountry('Togo')->setCity('Lomé');
        $existingTech = new Technology();
        $existingTech->setName('Angular');
        $em->persist($domain);
        $em->persist($mention);
        $em->persist($specialty);
        $em->persist($institution);
        $em->persist($existingTech);
        $em->flush();

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'soutenance',
            'publish_project[name]' => 'Plateforme de gestion des étudiants',
            'publish_project[domain]' => (string) $domain->getId(),
            'publish_project[mention]' => (string) $mention->getId(),
            'publish_project[specialty]' => (string) $specialty->getId(),
            'publish_project[institution]' => (string) $institution->getId(),
            'publish_project[technologiesInput]' => 'Angular,Java',
            'publish_project[githubUrl]' => 'https://github.com/jdupont/scolarite',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plateforme de gestion des étudiants');

        $project = $em->getRepository(Project::class)->findOneBy(['name' => 'Plateforme de gestion des étudiants']);
        self::assertNotNull($project);
        self::assertSame('en_attente', $project->getStatus()->value);
        self::assertSame('jean-dupont', $project->getOwner()->getSlug());
        self::assertCount(1, $project->getProofs());
        self::assertCount(2, $project->getTechnologies());
        self::assertNotNull($project->getSlug());

        // La technologie déjà existante ne doit pas être dupliquée
        $angularCount = $em->getRepository(Technology::class)->count(['name' => 'Angular']);
        self::assertSame(1, $angularCount);
    }

    public function testNonYoutubeUrlIsRejectedAsVideoProof(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'personnel',
            'publish_project[name]' => 'Projet avec fausse vidéo',
            'publish_project[youtubeUrl]' => 'https://vimeo.com/123456',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'URL YouTube valide');
        self::assertCount(0, $em->getRepository(Project::class)->findAll());
    }

    public function testNonGithubUrlIsRejectedAsGithubProof(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'personnel',
            'publish_project[name]' => 'Projet avec faux GitHub',
            'publish_project[githubUrl]' => 'https://gitlab.com/jdupont/scolarite',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'dépôt GitHub');
        self::assertCount(0, $em->getRepository(Project::class)->findAll());
    }

    public function testMentionAutreWithoutDomainIsRejected(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'personnel',
            'publish_project[name]' => 'Projet sans domaine',
            'publish_project[mention]' => 'autre',
            'publish_project[mentionOther]' => 'Nouvelle Mention',
            'publish_project[githubUrl]' => 'https://github.com/test/x',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sélectionnez ou précisez d\'abord un domaine');
        self::assertCount(0, $em->getRepository(Project::class)->findAll());
    }

    public function testAutreCreatesFullClassificationHierarchyAndUnverifiedInstitution(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'soutenance',
            'publish_project[name]' => 'Projet classification libre',
            'publish_project[domain]' => 'autre',
            'publish_project[domainOther]' => 'Sciences Juridiques',
            'publish_project[mention]' => 'autre',
            'publish_project[mentionOther]' => 'Droit privé',
            'publish_project[specialty]' => 'autre',
            'publish_project[specialtyOther]' => 'Droit des affaires',
            'publish_project[institution]' => 'autre',
            'publish_project[institutionOther]' => 'Institut Libre de Droit',
            'publish_project[githubUrl]' => 'https://github.com/test/droit',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();

        $project = $em->getRepository(Project::class)->findOneBy(['name' => 'Projet classification libre']);
        self::assertNotNull($project);
        self::assertSame('Sciences Juridiques', $project->getDomain()->getName());
        self::assertSame('Droit privé', $project->getMention()->getName());
        self::assertSame($project->getDomain(), $project->getMention()->getDomain());
        self::assertSame('Droit des affaires', $project->getSpecialty()->getName());
        self::assertSame($project->getMention(), $project->getSpecialty()->getMention());
        self::assertSame('Institut Libre de Droit', $project->getInstitution()->getName());
        self::assertFalse($project->getInstitution()->isVerified(), 'Un établissement ajouté par un utilisateur doit être non vérifié.');
    }

    #[DataProvider('forbiddenContentProvider')]
    public function testProjectRequestingFundingIsRejected(string $field, string $value): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form(array_merge([
            'publish_project[type]' => 'entrepreneurial',
            'publish_project[name]' => 'Projet de financement',
            'publish_project[githubUrl]' => 'https://github.com/test/financement',
        ], [$field => $value]));
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'plateforme de financement participatif');
        self::assertCount(0, $em->getRepository(Project::class)->findAll());
    }

    public static function forbiddenContentProvider(): iterable
    {
        yield 'recherche de financement' => ['publish_project[detailedDescription]', 'Nous recherchons un financement pour terminer ce projet.'];
        yield 'crowdfunding' => ['publish_project[shortDescription]', 'Lancement d\'une campagne de crowdfunding cette semaine.'];
        yield 'appel aux investisseurs' => ['publish_project[detailedDescription]', 'Ceci est un appel aux investisseurs pour financer la suite.'];
        yield 'demande de don' => ['publish_project[detailedDescription]', 'Vous pouvez faire un don pour soutenir ce projet.'];
    }

    public function testLegitimateDescriptionMentioningPastFundingIsNotFlagged(): void
    {
        $client = $this->createLoggedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/publier');
        $form = $crawler->selectButton('Envoyer pour publication')->form([
            'publish_project[type]' => 'entrepreneurial',
            'publish_project[name]' => 'Startup déjà lancée',
            'publish_project[detailedDescription]' => 'Application mobile de gestion de stock, déployée en production et utilisée par 40 commerces.',
            'publish_project[githubUrl]' => 'https://github.com/test/startup',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Startup déjà lancée');
        self::assertNotNull($em->getRepository(Project::class)->findOneBy(['name' => 'Startup déjà lancée']));
    }
}
