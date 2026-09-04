<?php

namespace App\Tests\Functional;

use App\Entity\AdminAuditLog;
use App\Entity\Defense;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Enum\DefenseStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Un administrateur peut vérifier directement une soutenance, sans attendre
 * les 2 confirmations du jury (utile pour un jury externe jamais inscrit,
 * ou une soutenance ancienne à régulariser).
 */
class AdminDefenseVerifyTest extends FunctionalTestCase
{
    private function createUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)->setFirstName('Test')->setLastName('User')
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)->setSlug($slug);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function createAnnouncedDefense(EntityManagerInterface $em, User $owner): Defense
    {
        $project = new Project();
        $project->setName('Projet à vérifier')->setType(ProjectType::SOUTENANCE)->setStatus(ProjectStatus::PUBLIE)->setSlug('projet-a-verifier-admin')->setOwner($owner);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project)->setDate(new \DateTimeImmutable('+3 days'))->setTime('10:00')->setPlace('Amphi C')->setStatus(DefenseStatus::ANNONCEE);
        $project->setDefense($defense);
        $em->persist($defense);

        return $defense;
    }

    public function testAdminCanVerifyAnAnnouncedDefenseDirectly(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin.defense@example.com', 'admin-defense', ['ROLE_ADMIN']);
        $owner = $this->createUser($em, 'candidat.defense@example.com', 'candidat-defense');
        $defense = $this->createAnnouncedDefense($em, $owner);
        $em->flush();
        $defenseId = $defense->getId();
        $projectId = $defense->getProject()->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$projectId);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action="'.static::getContainer()->get('router')->generate('admin_defense_verify', ['id' => $defenseId]).'"]')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/projets/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Defense::class)->find($defenseId);
        self::assertSame(DefenseStatus::VERIFIEE, $refreshed->getStatus());
        self::assertSame(ProjectStatus::VERIFIE, $refreshed->getProject()->getStatus(), 'Le projet doit aussi passer vérifié, comme pour une vérification par le jury.');

        $log = $em->getRepository(AdminAuditLog::class)->findOneBy(['action' => AdminAuditAction::DEFENSE_VERIFIED_BY_ADMIN]);
        self::assertNotNull($log, 'La vérification directe par un admin doit être journalisée.');
    }

    public function testCancelledDefenseNeverShowsTheVerifyButton(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->createUser($em, 'admin.defense2@example.com', 'admin-defense2', ['ROLE_ADMIN']);
        $owner = $this->createUser($em, 'candidat.defense2@example.com', 'candidat-defense2');
        $defense = $this->createAnnouncedDefense($em, $owner);
        $defense->setStatus(DefenseStatus::ANNULEE);
        $em->flush();
        $defenseId = $defense->getId();
        $projectId = $defense->getProject()->getId();

        $client->loginUser($admin);
        $client->request('GET', '/admin/projets/'.$projectId);
        self::assertResponseIsSuccessful();
        // Le formulaire lui-même est absent dès qu'une soutenance est
        // annulée (garde côté interface) — la garde serveur équivalente
        // (`verify()`, avant tout appel à `forceVerifyByAdmin()`) suit le
        // même motif déjà établi pour les autres actions de ce contrôleur.
        self::assertSelectorNotExists('form[action="'.static::getContainer()->get('router')->generate('admin_defense_verify', ['id' => $defenseId]).'"]');
    }

    /** Sécurité : réservé aux administrateurs. */
    public function testNonAdminCannotVerifyADefense(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'talent.notadmin.defense@example.com', 'talent-notadmin-defense');
        $owner = $this->createUser($em, 'candidat.defense3@example.com', 'candidat-defense3');
        $defense = $this->createAnnouncedDefense($em, $owner);
        $em->flush();

        $client->loginUser($talent);
        $client->request('POST', '/admin/soutenances/'.$defense->getId().'/verifier', ['_csrf_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }
}
