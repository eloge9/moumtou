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
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Une soutenance encore « annoncée » dont la date est passée doit
 * automatiquement devenir « réalisée » (sans clic manuel), et le dossier
 * « après la soutenance » doit rester désactivé tant que ce n'est pas le
 * cas — le bouton manuel restant, lui, toujours disponible pour une
 * confirmation anticipée.
 */
class DefenseAutoRealizeTest extends FunctionalTestCase
{
    private function createTalentWithDefense(EntityManagerInterface $em, \DateTimeImmutable $date, DefenseStatus $status = DefenseStatus::ANNONCEE): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('candidat.autorealize@example.com')
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setPhone('+22890000000')
            ->setRoles(['ROLE_TALENT'])
            ->setStatus(UserStatus::ACTIF)
            ->setSlug('jean-dupont-autorealize')
            ->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        $project = new Project();
        $project->setName('Projet auto-réalisation');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-auto-realisation');
        $project->setOwner($user);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate($date);
        $defense->setTime('14:00');
        $defense->setPlace('Amphi B');
        $defense->setStatus($status);
        $project->setDefense($defense);
        $em->persist($defense);

        $em->flush();

        return [$user, $project, $defense];
    }

    public function testPastAnnouncedDefenseAutoTransitionsToRealizedOnPageView(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        [$user] = $this->createTalentWithDefense($em, new \DateTimeImmutable('-3 days'));

        $client->loginUser($user);
        $crawler = $client->request('GET', '/ma-soutenance');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Réalisée');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $defense = $em->getRepository(Defense::class)->findOneBy([]);
        self::assertSame(DefenseStatus::REALISEE, $defense->getStatus());

        // Les champs "après la soutenance" doivent être exploitables.
        self::assertCount(0, $crawler->filter('input[name="video_url"][disabled]'));
    }

    public function testFutureAnnouncedDefenseStaysAnnouncedAndFieldsAreDisabled(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        [$user] = $this->createTalentWithDefense($em, new \DateTimeImmutable('+5 days'));

        $client->loginUser($user);
        $crawler = $client->request('GET', '/ma-soutenance');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $defense = $em->getRepository(Defense::class)->findOneBy([]);
        self::assertSame(DefenseStatus::ANNONCEE, $defense->getStatus(), 'Une soutenance future ne doit jamais être auto-marquée réalisée.');

        self::assertCount(1, $crawler->filter('input[name="video_url"][disabled]'), 'Les champs "après la soutenance" doivent être désactivés avant la date.');
        // Le bouton manuel reste disponible malgré la désactivation des champs.
        self::assertSelectorExists('form[action="'.static::getContainer()->get('router')->generate('app_defense_mark_realized', ['id' => $defense->getProject()->getId()]).'"]');
    }

    public function testMarkPastRealizedCommandIsIdempotent(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $this->createTalentWithDefense($em, new \DateTimeImmutable('-1 day'));

        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $command = $application->find('app:defense:mark-past-realized');
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 soutenance', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $defense = $em->getRepository(Defense::class)->findOneBy([]);
        self::assertSame(DefenseStatus::REALISEE, $defense->getStatus());

        // Deuxième exécution : plus rien à faire.
        $tester->execute([]);
        self::assertStringContainsString('Aucune soutenance', $tester->getDisplay());
    }
}
