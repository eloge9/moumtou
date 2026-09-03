<?php

namespace App\Tests\Functional;

use App\Entity\AdminAuditLog;
use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Enum\CommentStatus;
use App\Enum\NotificationType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 9 : journal d'administration,
 * notifications admin, modération des commentaires et gestion du
 * catalogue de technologies (doublons/fusion). Complète les tests déjà
 * existants pour les signalements et les sanctions.
 */
class AdminAuditJournalTest extends FunctionalTestCase
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

    public function testNonAdminRolesAreDeniedOnNewAdminRoutesAndAdminIsAuthorized(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'talent.f9@example.com', 'talent-f9', ['ROLE_TALENT']);
        $teacher = $this->createUser($em, 'teacher.f9@example.com', 'teacher-f9', ['ROLE_TEACHER']);
        $recruiter = $this->createUser($em, 'recruiter.f9@example.com', 'recruiter-f9', ['ROLE_RECRUITER']);
        $admin = $this->createUser($em, 'admin.f9@example.com', 'admin-f9', ['ROLE_ADMIN']);
        $em->flush();
        $adminId = $admin->getId();

        $routes = ['/admin/utilisateurs/'.$adminId, '/admin/commentaires', '/admin/journal'];

        // Visiteur anonyme : redirigé vers la connexion, jamais autorisé.
        // Doit être vérifié avant toute connexion (un seul client/kernel par test).
        foreach ($routes as $route) {
            $client->request('GET', $route);
            self::assertTrue($client->getResponse()->isRedirect(), $route.' should redirect an anonymous visitor to login');
        }

        foreach ([$talent, $teacher, $recruiter] as $user) {
            foreach ($routes as $route) {
                $client->loginUser($user);
                $client->request('GET', $route);
                self::assertSame(403, $client->getResponse()->getStatusCode(), $route.' should refuse '.implode(',', $user->getRoles()));
            }
        }

        $client->loginUser($admin);
        foreach ($routes as $route) {
            $client->request('GET', $route);
            self::assertResponseIsSuccessful($route.' should be reachable by an admin');
        }
    }

    public function testSuspendThenReactivateLogsAuditAndNotifiesTheUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin.sanction@example.com', 'admin-sanction', ['ROLE_ADMIN']);
        $target = $this->createUser($em, 'cible.sanction@example.com', 'cible-sanction', ['ROLE_TALENT']);
        $em->flush();
        $targetId = $target->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$targetId);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$targetId.'/sanctionner"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/utilisateurs/'.$targetId.'/sanctionner', [
            'action' => 'suspendre_7',
            'reason' => 'Comportement inapproprié constaté.',
            'redirect_to' => 'admin_user_show',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/utilisateurs/'.$targetId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(UserStatus::SUSPENDU, $em->getRepository(User::class)->find($targetId)->getStatus());

        $logs = $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $targetId, 'action' => AdminAuditAction::USER_SUSPENDED]);
        self::assertCount(1, $logs);

        $notifications = $em->getRepository(Notification::class)->findBy(['recipient' => $target, 'type' => NotificationType::ACCOUNT_SUSPENDED]);
        self::assertCount(1, $notifications);

        // Réactivation depuis la fiche utilisateur.
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$targetId);
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$targetId.'/sanctionner"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/utilisateurs/'.$targetId.'/sanctionner', [
            'action' => 'reactiver',
            'redirect_to' => 'admin_user_show',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/utilisateurs/'.$targetId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(UserStatus::ACTIF, $em->getRepository(User::class)->find($targetId)->getStatus());

        $reactivationLogs = $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $targetId, 'action' => AdminAuditAction::USER_UNSUSPENDED]);
        self::assertCount(1, $reactivationLogs);
    }

    public function testProjectVerificationLogsAuditAndNotifiesTheOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->createUser($em, 'owner.verif@example.com', 'owner-verif');
        $admin = $this->createUser($em, 'admin.verif@example.com', 'admin-verif', ['ROLE_ADMIN']);

        $project = new Project();
        $project->setName('Plateforme de covoiturage universitaire');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('covoiturage-verif-test');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$projectId);
        $form = $crawler->filter('form[action="/admin/moderation/projets/'.$projectId.'/decider"]')->selectButton('✅ Marquer comme vérifié')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/projets/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(ProjectStatus::VERIFIE, $em->getRepository(Project::class)->find($projectId)->getStatus());

        $logs = $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $projectId, 'action' => AdminAuditAction::PROJECT_VERIFIED]);
        self::assertCount(1, $logs);

        $notifications = $em->getRepository(Notification::class)->findBy(['recipient' => $owner, 'type' => NotificationType::PROJECT_VERIFIED]);
        self::assertCount(1, $notifications);
    }

    public function testAdminCommentsPageHidesAndRestoresAndLogs(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $author = $this->createUser($em, 'auteur.commentaire@example.com', 'auteur-commentaire');
        $admin = $this->createUser($em, 'admin.commentaire@example.com', 'admin-commentaire', ['ROLE_ADMIN']);

        $project = new Project();
        $project->setName('Assistant de révision par IA');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('assistant-revision-test');
        $project->setOwner($author);
        $em->persist($project);

        $comment = new Comment();
        $comment->setProject($project);
        $comment->setAuthor($author);
        $comment->setContent('Ceci est un commentaire tout à fait ordinaire.');
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/commentaires');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/admin/commentaires/'.$commentId.'/action"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/commentaires/'.$commentId.'/action', [
            'action' => 'masquer',
            'reason' => 'Hors sujet par rapport au projet.',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/commentaires');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(CommentStatus::MASQUE, $em->getRepository(Comment::class)->find($commentId)->getStatus());
        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $commentId, 'action' => AdminAuditAction::COMMENT_HIDDEN]));

        // Restauration.
        $crawler = $client->request('GET', '/admin/commentaires');
        $token = $crawler->filter('form[action="/admin/commentaires/'.$commentId.'/action"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/commentaires/'.$commentId.'/action', [
            'action' => 'restaurer',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/commentaires');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(CommentStatus::VISIBLE, $em->getRepository(Comment::class)->find($commentId)->getStatus());
        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $commentId, 'action' => AdminAuditAction::COMMENT_RESTORED]));
    }

    public function testNewReportNotifiesAllAdmins(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $author = $this->createUser($em, 'auteur.signale@example.com', 'auteur-signale');
        $reporter = $this->createUser($em, 'signaleur@example.com', 'signaleur');
        $admin1 = $this->createUser($em, 'admin1.signalement@example.com', 'admin1-signalement', ['ROLE_ADMIN']);
        $admin2 = $this->createUser($em, 'admin2.signalement@example.com', 'admin2-signalement', ['ROLE_ADMIN']);

        $project = new Project();
        $project->setName('Marketplace de freelances');
        $project->setType(ProjectType::ENTREPRENEURIAL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('marketplace-signalement-test');
        $project->setOwner($author);
        $em->persist($project);
        $em->flush();
        $slug = $project->getSlug();

        $client->loginUser($reporter);
        $crawler = $client->request('GET', '/projets/'.$slug);
        $token = $crawler->filter('form[action="/projets/'.$slug.'/signaler"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/projets/'.$slug.'/signaler', [
            'reason' => 'contenu_interdit',
            'details' => 'Contenu problématique.',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/projets/'.$slug);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $notifications = $em->getRepository(Notification::class)->findBy(['type' => NotificationType::REPORT_RECEIVED]);
        $recipientIds = array_map(static fn (Notification $n) => $n->getRecipient()->getId(), $notifications);

        self::assertCount(2, $notifications);
        self::assertContains($admin1->getId(), $recipientIds);
        self::assertContains($admin2->getId(), $recipientIds);
    }

    public function testTechnologyDuplicateIsRejectedCaseInsensitivelyAndMergeReassignsProjectsAndLogs(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin.catalog@example.com', 'admin-catalog', ['ROLE_ADMIN']);
        $owner = $this->createUser($em, 'owner.catalog@example.com', 'owner-catalog');

        $javaLower = new Technology();
        $javaLower->setName('java');
        $em->persist($javaLower);

        $javaScript = new Technology();
        $javaScript->setName('JavaScript');
        $em->persist($javaScript);

        $project = new Project();
        $project->setName('API de gestion de bibliothèque');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('api-bibliotheque-test');
        $project->setOwner($owner);
        $project->addTechnology($javaLower);
        $em->persist($project);
        $em->flush();
        $javaLowerId = $javaLower->getId();
        $javaScriptId = $javaScript->getId();
        $projectId = $project->getId();

        $client->loginUser($admin);

        // Doublon insensible à la casse rejeté ("Java" existe déjà via "java").
        $crawler = $client->request('GET', '/admin/technologies');
        $token = $crawler->filter('form[action="/admin/technologies/ajouter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/technologies/ajouter', ['name' => 'Java', '_csrf_token' => $token]);
        self::assertResponseRedirects('/admin/technologies');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(2, $em->getRepository(Technology::class)->findAll(), 'No new technology should have been created for the case-insensitive duplicate "Java".');

        // Fusion : "java" -> "JavaScript".
        $crawler = $client->request('GET', '/admin/technologies');
        $token = $crawler->filter('form[action="/admin/technologies/'.$javaLowerId.'/fusionner"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/technologies/'.$javaLowerId.'/fusionner', [
            'target' => $javaScriptId,
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/technologies');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Technology::class)->find($javaLowerId), 'The source technology should be deleted after merging.');
        $refreshedProject = $em->getRepository(Project::class)->find($projectId);
        $techIds = array_map(static fn (Technology $t) => $t->getId(), $refreshedProject->getTechnologies()->toArray());
        self::assertContains($javaScriptId, $techIds, 'The project should now be linked to the target technology after the merge.');

        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['action' => AdminAuditAction::TECHNOLOGY_MERGED]));
    }
}
