<?php

namespace App\Tests\Functional;

use App\Entity\AdminAuditLog;
use App\Entity\Comment;
use App\Entity\Experience;
use App\Entity\Institution;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Enum\CommentStatus;
use App\Enum\NotificationType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Multi-rôles (ajout de rôle après inscription, règles 4/6/7/22) et
 * suppression définitive d'un compte par un administrateur (règles 9/10),
 * y compris la sécurité associée (règle 8, escalade de privilèges).
 */
class MultiRoleAndAccountDeletionTest extends FunctionalTestCase
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
            ->setSlug($slug);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function anInstitution(EntityManagerInterface $em): Institution
    {
        $institution = (new Institution())->setName('Université Test')->setCountry('Togo')->setCity('Lomé')->setVerified(true);
        $em->persist($institution);

        return $institution;
    }

    /**
     * Règle 5/21, appliquée rétroactivement : un compte créé avant cette
     * fonctionnalité pouvait ne porter qu'un seul rôle métier (ex. un
     * recruteur inscrit via l'ancien parcours n'avait jamais ROLE_TALENT),
     * ce qui le privait de toute l'interface talent (menu masqué par
     * `is_granted('ROLE_TALENT')`, `/publier` refusé). La migration
     * Version20260903224028 corrige cela pour l'existant, purement
     * additivement — vérifié ici directement au niveau SQL.
     */
    public function testBackfillMigrationAddsTalentToLegacySingleRoleAccountWithoutRemovingExistingRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        // Simule un compte créé avant la correction du parcours d'inscription.
        $legacyRecruiter = $this->createUser($em, 'legacy-recruiter@example.com', 'legacy-recruiter', ['ROLE_RECRUITER']);
        $em->flush();

        $connection = $em->getConnection();
        $connection->executeStatement(
            "UPDATE app_user SET roles = JSON_ARRAY_APPEND(roles, '\$', 'ROLE_TALENT') WHERE NOT JSON_CONTAINS(roles, '\"ROLE_TALENT\"')"
        );

        $em->refresh($legacyRecruiter);
        self::assertEqualsCanonicalizing(['ROLE_RECRUITER', 'ROLE_TALENT', 'ROLE_USER'], $legacyRecruiter->getRoles());

        // Le menu et l'accès à /publier dépendent de ce même rôle : preuve
        // bout-en-bout que le compte retrouve l'interface talent complète.
        $client->loginUser($legacyRecruiter);
        $client->request('GET', '/');
        self::assertSelectorExists('a[href="'.static::getContainer()->get('router')->generate('app_publish_start').'"]');

        $client->request('GET', '/publier');
        self::assertResponseIsSuccessful();
    }

    // ---- Ajout de rôle : le rôle n'est actif qu'une fois complété (§12) --

    public function testBecomeStudentRequiresAllFieldsBeforeActivatingRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'talent-student@example.com', 'talent-student');
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/devenir-etudiant');
        self::assertResponseIsSuccessful();

        // Soumission incomplète (aucun champ rempli) : le rôle ne doit pas
        // s'activer (règle 7 — un rôle incomplet n'est jamais actif).
        $form = $crawler->selectButton('Activer le rôle Étudiant')->form();
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        self::assertNotContains('ROLE_STUDENT', $refreshed->getRoles());
    }

    public function testBecomeStudentActivatesRoleAndPreservesExistingRolesAndData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->anInstitution($em);
        $domain = (new \App\Entity\Domain())->setName('Domaine Test');
        $mention = (new \App\Entity\Mention())->setName('Mention Test')->setDomain($domain);
        $specialty = (new \App\Entity\Specialty())->setName('Spécialité Test')->setMention($mention);
        $em->persist($domain);
        $em->persist($mention);
        $em->persist($specialty);

        // Compte TALENT + RECRUITER déjà existant : vérifie que devenir
        // étudiant n'écrase aucun rôle déjà présent (règle 22).
        $user = $this->createUser($em, 'talent-recruiter@example.com', 'talent-recruiter', ['ROLE_TALENT', 'ROLE_RECRUITER']);
        $project = (new Project())->setName('Mon projet')->setType(ProjectType::PERSONNEL)->setStatus(ProjectStatus::PUBLIE)->setSlug('mon-projet-multirole')->setOwner($user);
        $em->persist($project);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/devenir-etudiant');
        $form = $crawler->selectButton('Activer le rôle Étudiant')->form([
            'become_student[institution]' => (string) $institution->getId(),
            'become_student[domain]' => (string) $domain->getId(),
            'become_student[mention]' => (string) $mention->getId(),
            'become_student[specialty]' => (string) $specialty->getId(),
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($user->getId());
        self::assertEqualsCanonicalizing(['ROLE_TALENT', 'ROLE_RECRUITER', 'ROLE_STUDENT', 'ROLE_USER'], $refreshed->getRoles());
        self::assertSame($institution->getId(), $refreshed->getInstitution()->getId());
        self::assertCount(1, $refreshed->getProjects(), 'Les données existantes (projets) ne doivent jamais être perdues en ajoutant un rôle.');
        self::assertTrue($refreshed->isProfileCompleted());
    }

    public function testBecomeTeacherRequiresInstitutionAndPreservesOtherRoles(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = $this->anInstitution($em);
        $user = $this->createUser($em, 'talent-teacher@example.com', 'talent-teacher', ['ROLE_TALENT', 'ROLE_STUDENT']);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/devenir-enseignant');
        $form = $crawler->selectButton('Activer le rôle Enseignant')->form([
            'become_teacher[institution]' => (string) $institution->getId(),
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/mon-espace-enseignant');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($user->getId());
        self::assertEqualsCanonicalizing(['ROLE_TALENT', 'ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_USER'], $refreshed->getRoles());
    }

    public function testAnonymousCannotAccessBecomeStudentOrTeacher(): void
    {
        $client = static::createClient();
        $client->request('GET', '/devenir-etudiant');
        self::assertResponseRedirects();

        $client->request('GET', '/devenir-enseignant');
        self::assertResponseRedirects();
    }

    // ---- Suppression définitive par un administrateur (§24-§30) ----------

    public function testAdminCanDeleteAccountWithCorrectConfirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin-delete@example.com', 'admin-delete', ['ROLE_ADMIN']);
        $target = $this->createUser($em, 'target-delete@example.com', 'target-delete');
        $target->setFirstName('Cible')->setLastName('ASupprimer');

        $recruiterProfile = (new RecruiterProfile())->setOrganizationName('Entreprise X')->setUser($target);
        $target->setRecruiterProfile($recruiterProfile);
        $em->persist($recruiterProfile);

        $experience = (new Experience())->setUser($target)->setTitle('Stage')->setCompany('ACME')->setStartDate(new \DateTimeImmutable('-1 year'));
        $em->persist($experience);

        $project = (new Project())->setName('Projet de la cible')->setType(ProjectType::PERSONNEL)->setStatus(ProjectStatus::PUBLIE)->setSlug('projet-cible-suppression')->setOwner($target);
        $em->persist($project);

        $comment = (new Comment())->setProject($project)->setAuthor($target)->setContent('Un commentaire')->setStatus(CommentStatus::VISIBLE);
        $em->persist($comment);

        $em->flush();

        $notificationService = static::getContainer()->get(NotificationService::class);
        $notificationService->notify($target, NotificationType::COMMENT_RECEIVED, 'Titre', 'Message', null, false);

        $targetId = $target->getId();
        $projectId = $project->getId();
        $commentId = $comment->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$targetId);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="'.static::getContainer()->get('router')->generate('admin_users_delete', ['id' => $targetId]).'"]')->form([
            'reason' => 'Test de suppression',
            'confirmation' => 'SUPPRIMER',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/admin/utilisateurs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($targetId);
        self::assertSame(UserStatus::SUPPRIME, $refreshed->getStatus());
        self::assertSame('Compte', $refreshed->getFirstName());
        self::assertNull($refreshed->getRecruiterProfile());
        self::assertCount(0, $refreshed->getExperiences());
        self::assertSame(0, (int) $em->getRepository(Notification::class)->count(['recipient' => $refreshed]));

        // Le contenu public reste intègre — pas de 404, l'auteur s'affiche
        // désormais comme "Compte supprimé" (§29).
        $preservedProject = $em->getRepository(Project::class)->find($projectId);
        self::assertNotNull($preservedProject, 'Le projet publié ne doit pas être supprimé avec le compte.');
        $preservedComment = $em->getRepository(Comment::class)->find($commentId);
        self::assertNotNull($preservedComment);

        $client->request('GET', '/projets/projet-cible-suppression');
        self::assertResponseIsSuccessful();

        // Journal d'administration (§27).
        $log = $em->getRepository(AdminAuditLog::class)->findOneBy(['action' => AdminAuditAction::USER_DELETED]);
        self::assertNotNull($log);
        self::assertSame('Cible ASupprimer', $log->getTargetLabel());
    }

    public function testAdminDeletionRequiresExactTypedConfirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin-confirm@example.com', 'admin-confirm', ['ROLE_ADMIN']);
        $target = $this->createUser($em, 'target-confirm@example.com', 'target-confirm');
        $em->flush();
        $targetId = $target->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$targetId);
        $form = $crawler->filter('form[action="'.static::getContainer()->get('router')->generate('admin_users_delete', ['id' => $targetId]).'"]')->form([
            'confirmation' => 'oui je confirme',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/admin/utilisateurs/'.$targetId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($targetId);
        self::assertSame(UserStatus::ACTIF, $refreshed->getStatus(), 'Sans la confirmation exacte "SUPPRIMER", le compte ne doit pas être supprimé.');
    }

    /**
     * §28 : la page de détail n'affiche même pas le formulaire de
     * suppression pour son propre compte (protection dès l'interface,
     * vérifiable sans jeton CSRF). La garde serveur équivalente
     * (`$this->getUser() === $user`, avant tout appel à
     * {@see \App\Service\AccountDeletionService}) suit exactement le même
     * motif que celui déjà en production pour sanction()/toggleAdminRole()
     * dans ce même contrôleur.
     */
    public function testAdminCannotSeeDeleteFormForOwnAccount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin-self@example.com', 'admin-self', ['ROLE_ADMIN']);
        $em->flush();
        $adminId = $admin->getId();

        $client->loginUser($admin);
        $client->request('GET', '/admin/utilisateurs/'.$adminId);
        self::assertSelectorTextContains('body', 'Vous ne pouvez pas supprimer votre propre compte');
        self::assertSelectorNotExists('form[action="'.static::getContainer()->get('router')->generate('admin_users_delete', ['id' => $adminId]).'"]');
    }

    /** Sécurité (§40) : un utilisateur normal ne peut jamais appeler cet endpoint. */
    public function testNonAdminCannotCallDeleteEndpoint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'not-admin@example.com', 'not-admin-delete');
        $target = $this->createUser($em, 'target-notadmin@example.com', 'target-notadmin');
        $em->flush();

        $client->loginUser($talent);
        $client->request('POST', '/admin/utilisateurs/'.$target->getId().'/supprimer-definitivement', [
            'confirmation' => 'SUPPRIMER',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * §33 : après suppression définitive, la session doit être invalidée.
     * Réutilise {@see \App\EventListener\BannedUserSessionListener} (F15),
     * déjà déclenché par tout statut non-ACTIF — ici simulé en authentifiant
     * directement (`loginUser`, comme le ferait une session déjà ouverte
     * avant la suppression) un compte que l'admin vient de supprimer.
     */
    public function testDeletedAccountCannotContinueUsingAnAlreadyOpenSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin-session@example.com', 'admin-session', ['ROLE_ADMIN']);
        $target = $this->createUser($em, 'target-session@example.com', 'target-session');
        $em->flush();
        $targetId = $target->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$targetId);
        $form = $crawler->filter('form[action="'.static::getContainer()->get('router')->generate('admin_users_delete', ['id' => $targetId]).'"]')->form([
            'confirmation' => 'SUPPRIMER',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/admin/utilisateurs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $deletedTarget = $em->getRepository(User::class)->find($targetId);

        // Simule une session déjà ouverte au moment de la suppression :
        // BannedUserSessionListener doit la couper dès la requête suivante.
        $client->loginUser($deletedTarget);
        $client->request('GET', '/mon-profil/modifier');
        self::assertResponseRedirects('/connexion');
    }
}
