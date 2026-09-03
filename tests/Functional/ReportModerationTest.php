<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §17 à §20, §35 : signalement de projet, décision de
 * l'administrateur (rejet ou action), modération d'un commentaire signalé.
 */
class ReportModerationTest extends FunctionalTestCase
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

    public function testUserCanReportAProjectAndDuplicateIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.reportp@example.com', 'owner-reportp');
        $project = new Project();
        $project->setName('Projet signalé');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-signale');
        $project->setOwner($owner);
        $em->persist($project);

        $reporter = $this->makeUser($em, 'reporter.reportp@example.com', 'reporter-reportp');
        $em->flush();

        $client->loginUser($reporter);
        $crawler = $client->request('GET', '/projets/projet-signale');
        $token = $crawler->filter('#modale-signalement input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/projets/projet-signale/signaler', ['reason' => 'faux_projet', '_csrf_token' => $token]);
        self::assertResponseRedirects('/projets/projet-signale');

        $client->request('POST', '/projets/projet-signale/signaler', ['reason' => 'spam', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $reports = $em->getRepository(Report::class)->findBy(['targetId' => $project->getId(), 'targetType' => ReportTargetType::PROJECT]);
        self::assertCount(1, $reports, 'Un second signalement du même utilisateur sur le même projet ne doit pas créer de doublon.');
    }

    public function testNonAdminCannotAccessModeration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $user = $this->makeUser($em, 'simple.user@example.com', 'simple-user');
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin/moderation');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanRejectAnUnfoundedReport(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.rejet@example.com', 'owner-rejet');
        $project = new Project();
        $project->setName('Projet correct');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-non-fonde');
        $project->setOwner($owner);
        $em->persist($project);

        $reporter = $this->makeUser($em, 'reporter.rejet@example.com', 'reporter-rejet');
        $admin = $this->makeUser($em, 'admin.rejet@example.com', 'admin-rejet', ['ROLE_ADMIN']);
        $em->flush();

        $report = new Report();
        $report->setReporter($reporter);
        $report->setTargetType(ReportTargetType::PROJECT);
        $report->setTargetId($project->getId());
        $report->setReason(\App\Enum\ReportReason::AUTRE);
        $em->persist($report);
        $em->flush();
        $reportId = $report->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/moderation/signalements/'.$reportId);
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        // Aucune action sur le contenu, aucune sanction : le signalement est rejeté.
        $client->request('POST', '/admin/moderation/signalements/'.$reportId.'/decider', [
            'reason' => 'Projet légitime, aucune infraction constatée.',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/moderation');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Report::class)->find($reportId);
        self::assertSame(ReportStatus::REJETE, $refreshed->getStatus());
        self::assertSame(ProjectStatus::PUBLIE, $em->getRepository(Project::class)->find($project->getId())->getStatus(), 'Le contenu doit rester inchangé pour un signalement rejeté.');
    }

    public function testAdminCanHideAReportedComment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.hide@example.com', 'owner-hide');
        $project = new Project();
        $project->setName('Projet avec commentaire signalé');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-commentaire-a-masquer');
        $project->setOwner($owner);
        $em->persist($project);

        $commenter = $this->makeUser($em, 'commenter.hide@example.com', 'commenter-hide');
        $comment = new Comment();
        $comment->setProject($project);
        $comment->setAuthor($commenter);
        $comment->setContent('Propos problématique.');
        $em->persist($comment);

        $reporter = $this->makeUser($em, 'reporter.hide@example.com', 'reporter-hide');
        $admin = $this->makeUser($em, 'admin.hide@example.com', 'admin-hide', ['ROLE_ADMIN']);
        $em->flush();
        $commentId = $comment->getId();

        $report = new Report();
        $report->setReporter($reporter);
        $report->setTargetType(ReportTargetType::COMMENT);
        $report->setTargetId($commentId);
        $report->setReason(\App\Enum\ReportReason::CONTENU_OFFENSANT);
        $em->persist($report);
        $em->flush();
        $reportId = $report->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/moderation/signalements/'.$reportId);
        self::assertSelectorTextContains('body', 'Propos problématique.');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/moderation/signalements/'.$reportId.'/decider', [
            'content_action' => 'masquer',
            'reason' => 'Contenu inapproprié confirmé.',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/moderation');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshedReport = $em->getRepository(Report::class)->find($reportId);
        self::assertSame(ReportStatus::TRAITE, $refreshedReport->getStatus());
        $refreshedComment = $em->getRepository(Comment::class)->find($commentId);
        self::assertSame(CommentStatus::MASQUE, $refreshedComment->getStatus());

        // Le commentaire masqué ne doit plus apparaître publiquement.
        $client->request('GET', '/projets/projet-commentaire-a-masquer');
        self::assertSelectorTextNotContains('body', 'Propos problématique.');
    }
}
