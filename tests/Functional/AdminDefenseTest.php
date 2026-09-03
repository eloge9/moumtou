<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminDefenseTest extends FunctionalTestCase
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

    public function testAdminCanListAndFilterDefenses(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.defenses@example.com', ['ROLE_ADMIN'], 'admin-defenses');
        $owner = $this->makeUser($em, 'owner.admindefense@example.com', ['ROLE_TALENT'], 'owner-admindefense');

        $project = new Project();
        $project->setName('Soutenance à filtrer');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('soutenance-a-filtrer');
        $project->setOwner($owner);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate(new \DateTimeImmutable('2026-12-01'));
        $defense->setTime('09:00');
        $defense->setPlace('Amphi Z');
        $defense->setStatus(DefenseStatus::ANNONCEE);
        $project->setDefense($defense);
        $em->persist($defense);
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', '/admin/soutenances');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Soutenance à filtrer');

        $client->request('GET', '/admin/soutenances?filtre=a_venir');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Soutenance à filtrer');

        $client->request('GET', '/admin/soutenances?filtre=verifiees');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Soutenance à filtrer');
    }

    public function testNonAdminCannotAccessDefenseAdministration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.noadmindefense@example.com', ['ROLE_TALENT'], 'talent-noadmindefense');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/admin/soutenances');
        self::assertResponseStatusCodeSame(403);
    }
}
