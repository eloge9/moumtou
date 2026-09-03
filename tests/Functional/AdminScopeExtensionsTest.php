<?php

namespace App\Tests\Functional;

use App\Entity\AdminAuditLog;
use App\Entity\Domain;
use App\Entity\Mention;
use App\Entity\Project;
use App\Entity\Specialty;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges — FONCTIONNALITÉ 9 §29/§31/§37 : désactivation des
 * référentiels de classification (sans casser l'existant) et attribution
 * protégée/journalisée du rôle ROLE_ADMIN.
 */
class AdminScopeExtensionsTest extends FunctionalTestCase
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

    public function testDeactivatedDomainDisappearsFromNewSelectionButStaysUsableOnExistingProject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin.classif@example.com', 'admin-classif', ['ROLE_ADMIN']);
        $talent = $this->createUser($em, 'talent.classif@example.com', 'talent-classif', ['ROLE_TALENT']);

        $domain = new Domain();
        $domain->setName('Domaine à désactiver');
        $em->persist($domain);

        $project = new Project();
        $project->setName('Projet historique');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-historique-test');
        $project->setOwner($talent);
        $project->setDomain($domain);
        $em->persist($project);
        $em->flush();
        $domainId = $domain->getId();

        // L'admin désactive le domaine.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/classification');
        $token = $crawler->filter('form[action="/admin/classification/domaine/'.$domainId.'/desactiver"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/classification/domaine/'.$domainId.'/desactiver', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/admin/classification');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertFalse($em->getRepository(Domain::class)->find($domainId)->isActive());
        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $domainId, 'action' => AdminAuditAction::DOMAIN_DEACTIVATED]));

        // Un NOUVEAU projet ne doit plus pouvoir sélectionner ce domaine.
        $client->loginUser($talent);
        $crawler = $client->request('GET', '/publier');
        $optionValues = $crawler->filter('select[name="publish_project[domain]"] option')->extract(['value']);
        self::assertNotContains((string) $domainId, $optionValues, 'A deactivated domain must not be offered for a new project.');

        // L'édition du projet existant doit toujours proposer ce domaine
        // (sinon la simple ouverture du formulaire ferait perdre la valeur).
        $crawler = $client->request('GET', '/projets/projet-historique-test/modifier');
        $optionValues = $crawler->filter('select[name="publish_project[domain]"] option')->extract(['value']);
        self::assertContains((string) $domainId, $optionValues, 'The domain already assigned to an existing project must remain selectable when editing it.');

        // Réactivation : redevient sélectionnable pour un nouveau projet.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/classification');
        $token = $crawler->filter('form[action="/admin/classification/domaine/'.$domainId.'/desactiver"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/classification/domaine/'.$domainId.'/desactiver', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->getRepository(Domain::class)->find($domainId)->isActive());
        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $domainId, 'action' => AdminAuditAction::DOMAIN_REACTIVATED]));
    }

    public function testDeactivatingMentionAndSpecialtyAlsoLogsAndFiltersSelection(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin.classif2@example.com', 'admin-classif2', ['ROLE_ADMIN']);

        $domain = new Domain();
        $domain->setName('Domaine parent');
        $em->persist($domain);
        $mention = new Mention();
        $mention->setName('Mention à désactiver');
        $mention->setDomain($domain);
        $em->persist($mention);
        $specialty = new Specialty();
        $specialty->setName('Spécialité à désactiver');
        $specialty->setMention($mention);
        $em->persist($specialty);
        $em->flush();
        $mentionId = $mention->getId();
        $specialtyId = $specialty->getId();
        // La collection inverse Domain::$mentions n'est jamais synchronisée
        // en mémoire lors d'un simple setDomain() côté propriétaire ; sans
        // ce clear(), le contrôleur réutiliserait l'objet PHP encore vide
        // (aucune requête HTTP n'a encore eu lieu pour réinitialiser l'EM).
        $em->clear();

        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/classification');
        $token = $crawler->filter('form[action="/admin/classification/mention/'.$mentionId.'/desactiver"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/classification/mention/'.$mentionId.'/desactiver', ['_csrf_token' => $token]);

        $crawler = $client->request('GET', '/admin/classification');
        $token = $crawler->filter('form[action="/admin/classification/specialite/'.$specialtyId.'/desactiver"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/classification/specialite/'.$specialtyId.'/desactiver', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertFalse($em->getRepository(Mention::class)->find($mentionId)->isActive());
        self::assertFalse($em->getRepository(Specialty::class)->find($specialtyId)->isActive());
        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $mentionId, 'action' => AdminAuditAction::MENTION_DEACTIVATED]));
        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $specialtyId, 'action' => AdminAuditAction::SPECIALTY_DEACTIVATED]));
    }

    public function testAdminCanGrantAndRevokeAdminRoleButNotOnOwnAccount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin.role@example.com', 'admin-role', ['ROLE_ADMIN']);
        $target = $this->createUser($em, 'cible.role@example.com', 'cible-role', ['ROLE_TALENT']);
        $em->flush();
        $adminId = $admin->getId();
        $targetId = $target->getId();

        $client->loginUser($admin);

        // L'UI n'affiche aucun formulaire de modification de rôle sur sa
        // propre fiche compte : l'auto-modification n'est jamais possible
        // depuis le parcours normal de l'administrateur.
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$adminId);
        self::assertCount(0, $crawler->filter('form[action="/admin/utilisateurs/'.$adminId.'/role"]'));
        self::assertSelectorTextContains('body', 'Vous ne pouvez pas modifier vos propres droits');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertContains('ROLE_ADMIN', $em->getRepository(User::class)->find($adminId)->getRoles());

        // Attribution des droits admin à un talent.
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$targetId);
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$targetId.'/role"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/utilisateurs/'.$targetId.'/role', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/admin/utilisateurs/'.$targetId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshedTarget = $em->getRepository(User::class)->find($targetId);
        self::assertContains('ROLE_ADMIN', $refreshedTarget->getRoles());
        self::assertContains('ROLE_TALENT', $refreshedTarget->getRoles(), 'Granting ROLE_ADMIN must not remove the existing business role.');
        self::assertCount(1, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $targetId, 'action' => AdminAuditAction::USER_ROLE_CHANGED]));

        // Le compte promu peut désormais accéder au back-office.
        $client->loginUser($refreshedTarget);
        $client->request('GET', '/admin/journal');
        self::assertResponseIsSuccessful();

        // Retrait des droits admin.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/utilisateurs/'.$targetId);
        $token = $crawler->filter('form[action="/admin/utilisateurs/'.$targetId.'/role"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/utilisateurs/'.$targetId.'/role', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshedTarget = $em->getRepository(User::class)->find($targetId);
        self::assertNotContains('ROLE_ADMIN', $refreshedTarget->getRoles());
        self::assertCount(2, $em->getRepository(AdminAuditLog::class)->findBy(['targetId' => $targetId, 'action' => AdminAuditAction::USER_ROLE_CHANGED]));
    }
}
