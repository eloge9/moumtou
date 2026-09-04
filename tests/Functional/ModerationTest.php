<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ModerationTest extends FunctionalTestCase
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

    public function testAdminCanPublishAPendingProjectFromTheDashboard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $author = $this->createUser($em, 'auteur@example.com', 'auteur');
        $admin = $this->createUser($em, 'admin@example.com', 'admin-test', ['ROLE_ADMIN']);

        $project = new Project();
        $project->setName('Application de gestion de pharmacie');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::EN_ATTENTE);
        $project->setSlug('pharmacie-test');
        $project->setOwner($author);
        $em->persist($project);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin');
        $form = $crawler->filter('form[action="/admin/moderation/projets/'.$projectId.'/decider"]')->selectButton('Publier')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/projets/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        self::assertSame('publie', $project->getStatus()->value);
    }

    public function testAdminCanPublishAllPendingProjectsAtOnce(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $author = $this->createUser($em, 'auteur-groupe@example.com', 'auteur-groupe');
        $admin = $this->createUser($em, 'admin-groupe@example.com', 'admin-groupe');
        $admin->setRoles(['ROLE_ADMIN']);

        $projectIds = [];
        for ($i = 1; $i <= 3; ++$i) {
            $project = new Project();
            $project->setName('Projet en attente '.$i);
            $project->setType(ProjectType::PERSONNEL);
            $project->setStatus(ProjectStatus::EN_ATTENTE);
            $project->setSlug('projet-attente-'.$i);
            $project->setOwner($author);
            $em->persist($project);
            $projectIds[] = $project;
        }
        $em->flush();
        $projectIds = array_map(static fn (Project $p) => $p->getId(), $projectIds);

        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/moderation');
        $form = $crawler->selectButton('Tout publier (3)')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/moderation');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($projectIds as $id) {
            $project = $em->getRepository(Project::class)->find($id);
            self::assertSame('publie', $project->getStatus()->value);
        }
        self::assertCount(3, $em->getRepository(\App\Entity\ModerationAction::class)->findAll());
    }

    public function testAdminCanDecideOnAReportAndSuspendTheAuthor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $author = $this->createUser($em, 'spammeur@example.com', 'spammeur');
        $admin = $this->createUser($em, 'admin2@example.com', 'admin-test-2', ['ROLE_ADMIN']);
        $reporter = $this->createUser($em, 'reporter@example.com', 'reporter');

        $project = new Project();
        $project->setName('Levée de fonds agri-tech');
        $project->setType(ProjectType::ENTREPRENEURIAL);
        $project->setStatus(ProjectStatus::EN_ATTENTE);
        $project->setSlug('agri-tech-test');
        $project->setOwner($author);
        $em->persist($project);
        $em->flush();

        $report = new Report();
        $report->setReporter($reporter);
        $report->setTargetType(ReportTargetType::PROJECT);
        $report->setTargetId($project->getId());
        $report->setReason(ReportReason::DEMANDE_FINANCEMENT);
        $em->persist($report);
        $em->flush();
        $reportId = $report->getId();
        $projectId = $project->getId();
        $authorId = $author->getId();

        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/moderation/signalements/'.$reportId);
        $form = $crawler->selectButton('Valider la décision')->form([
            'content_action' => 'supprimer',
            'author_action' => 'suspendre_7',
            'reason' => 'Contenu de type appel à financement, non conforme aux règles.',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/admin/moderation');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        $author = $em->getRepository(User::class)->find($authorId);
        $report = $em->getRepository(Report::class)->find($reportId);

        self::assertSame('rejete', $project->getStatus()->value);
        self::assertSame('suspendu', $author->getStatus()->value);
        self::assertSame('traite', $report->getStatus()->value);
        self::assertCount(1, $em->getRepository(\App\Entity\Sanction::class)->findAll());
        self::assertCount(1, $em->getRepository(\App\Entity\ModerationAction::class)->findAll());
    }
}
