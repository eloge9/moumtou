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

class DefenseCancelPostponeTest extends FunctionalTestCase
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

    private function makeAnnouncedDefense(EntityManagerInterface $em, User $owner, string $slug, \DateTimeImmutable $date): array
    {
        $project = new Project();
        $project->setName('Projet '.$slug);
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate($date);
        $defense->setTime('10:00');
        $defense->setPlace('Amphi A');
        $defense->setStatus(DefenseStatus::ANNONCEE);
        $project->setDefense($defense);
        $em->persist($defense);
        $em->flush();

        return [$project, $defense];
    }

    public function testOwnerCanCancelAnAnnouncedDefenseWithReason(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.cancel@example.com', ['ROLE_TALENT'], 'owner-cancel');
        [$project, $defense] = $this->makeAnnouncedDefense($em, $owner, 'projet-a-annuler', new \DateTimeImmutable('2026-12-01'));
        $projectId = $project->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/ma-soutenance');
        $token = $crawler->filter('form[action="/ma-soutenance/'.$projectId.'/annuler"] input[name="_csrf_token"]')->attr('value');

        // Sans motif : refusé.
        $client->request('POST', '/ma-soutenance/'.$projectId.'/annuler', ['reason' => '', '_csrf_token' => $token]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(DefenseStatus::ANNONCEE, $em->getRepository(Project::class)->find($projectId)->getDefense()->getStatus());

        // Avec motif : annulée.
        $client->request('POST', '/ma-soutenance/'.$projectId.'/annuler', ['reason' => 'Salle indisponible', '_csrf_token' => $token]);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($projectId)->getDefense();
        self::assertSame(DefenseStatus::ANNULEE, $refreshed->getStatus());
        self::assertSame('Salle indisponible', $refreshed->getCancellationReason());
    }

    public function testCannotCancelAnAlreadyRealizedDefense(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.cancel2@example.com', ['ROLE_TALENT'], 'owner-cancel2');
        [$project, $defense] = $this->makeAnnouncedDefense($em, $owner, 'projet-realise-annulation', new \DateTimeImmutable('2026-09-01'));
        $defense->setStatus(DefenseStatus::REALISEE);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($owner);
        $client->request('POST', '/ma-soutenance/'.$projectId.'/annuler', ['reason' => 'Test', '_csrf_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(404, 'Une soutenance déjà réalisée ne doit plus pouvoir être annulée.');
    }

    public function testOtherUserCannotCancelSomeoneElsesDefense(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.cancel3@example.com', ['ROLE_TALENT'], 'owner-cancel3');
        [$project] = $this->makeAnnouncedDefense($em, $owner, 'projet-cancel3', new \DateTimeImmutable('2026-12-01'));
        $projectId = $project->getId();

        $intruder = $this->makeUser($em, 'intrus.cancel3@example.com', ['ROLE_TALENT'], 'intrus-cancel3');
        $em->flush();

        $client->loginUser($intruder);
        $client->request('POST', '/ma-soutenance/'.$projectId.'/annuler', ['reason' => 'Test', '_csrf_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCancelAnyDefense(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.cancel4@example.com', ['ROLE_TALENT'], 'owner-cancel4');
        [$project] = $this->makeAnnouncedDefense($em, $owner, 'projet-cancel4', new \DateTimeImmutable('2026-12-01'));
        $projectId = $project->getId();

        $admin = $this->makeUser($em, 'admin.cancel4@example.com', ['ROLE_ADMIN'], 'admin-cancel4');
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/projets/'.$project->getId());
        $token = $crawler->filter('form[action="/ma-soutenance/'.$projectId.'/annuler"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/ma-soutenance/'.$projectId.'/annuler', ['reason' => 'Décision administrative', '_csrf_token' => $token]);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(DefenseStatus::ANNULEE, $em->getRepository(Project::class)->find($projectId)->getDefense()->getStatus());
    }

    public function testPostponeThenRescheduleReturnsToAnnounced(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.postpone@example.com', ['ROLE_TALENT'], 'owner-postpone');
        [$project, $defense] = $this->makeAnnouncedDefense($em, $owner, 'projet-report', new \DateTimeImmutable('2026-12-01'));
        $projectId = $project->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/ma-soutenance');
        $token = $crawler->filter('form[action="/ma-soutenance/'.$projectId.'/reporter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/ma-soutenance/'.$projectId.'/reporter', ['reason' => 'Indisponibilité du jury', '_csrf_token' => $token]);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($projectId)->getDefense();
        self::assertSame(DefenseStatus::REPORTEE, $refreshed->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-01'), $refreshed->getPreviousDate());

        // Impossible d'inviter un jury tant qu'aucune nouvelle date n'est fixée.
        $crawler = $client->request('GET', '/ma-soutenance');
        self::assertSelectorTextContains('body', 'reportée');

        // Reprogrammation.
        $crawler = $client->request('GET', '/ma-soutenance');
        $form = $crawler->filter('form[action="/ma-soutenance/'.$projectId.'/reprogrammer"]')->form([
            'defense_announce[date]' => '2027-01-15',
            'defense_announce[time]' => '14:00',
            'defense_announce[place]' => 'Amphi B',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Project::class)->find($projectId)->getDefense();
        self::assertSame(DefenseStatus::ANNONCEE, $refreshed->getStatus());
        self::assertSame('2027-01-15', $refreshed->getDate()->format('Y-m-d'));
        self::assertNull($refreshed->getReminderSentAt());
    }

    public function testPublicPageReflectsPostponedAndCancelledStates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.publicstates@example.com', ['ROLE_TALENT'], 'owner-publicstates');
        [$project, $defense] = $this->makeAnnouncedDefense($em, $owner, 'projet-etats-publics', new \DateTimeImmutable('2026-12-01'));
        $defense->setStatus(DefenseStatus::ANNULEE);
        $defense->setCancellationReason('Test');
        $em->flush();

        $client->request('GET', '/soutenances/projet-etats-publics');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'SOUTENANCE ANNULÉE');
    }
}
