<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\JuryMember;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use App\Security\JuryInvitationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DefenseJuryTest extends FunctionalTestCase
{
    public function testFullAnnounceInviteRealizeAndVerifyFlowRequiresTwoDistinctValidations(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // garde le même EntityManager entre les requêtes du test
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('etudiant@example.com')
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setPhone('+22890000000')
            ->setRoles(['ROLE_TALENT'])
            ->setStatus(UserStatus::ACTIF)
            ->setSlug('jean-dupont')
            ->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        $project = new Project();
        $project->setName('Plateforme de gestion des étudiants');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('plateforme-test');
        $project->setOwner($user);
        $em->persist($project);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($user);

        // 1. Annoncer la soutenance
        $crawler = $client->request('GET', '/ma-soutenance');
        $form = $crawler->selectButton('Publier l\'annonce')->form([
            'defense_announce[date]' => '2026-09-15',
            'defense_announce[time]' => '14:00',
            'defense_announce[place]' => 'Amphi B',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        // Les requêtes HTTP réinitialisent l'EntityManager de test : on
        // récupère systématiquement des entités fraîches par leur identifiant
        // plutôt que de rafraîchir une référence potentiellement détachée.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        self::assertNotNull($project->getDefense());
        self::assertSame('annoncee', $project->getDefense()->getStatus()->value);

        // 2. Inviter deux membres du jury (le seuil de vérification est de 2)
        $crawler = $client->request('GET', '/ma-soutenance');
        $form = $crawler->selectButton('+ Inviter')->form([
            'jury_invite[firstName]' => 'A.',
            'jury_invite[lastName]' => 'Kodjo',
            'jury_invite[role]' => 'president',
            'jury_invite[email]' => 'akodjo@example.com',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $crawler = $client->request('GET', '/ma-soutenance');
        $form = $crawler->selectButton('+ Inviter')->form([
            'jury_invite[firstName]' => 'B.',
            'jury_invite[lastName]' => 'Amah',
            'jury_invite[role]' => 'rapporteur',
            'jury_invite[email]' => 'bamah@example.com',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $juryA = $em->getRepository(JuryMember::class)->findOneBy(['email' => 'akodjo@example.com']);
        $juryB = $em->getRepository(JuryMember::class)->findOneBy(['email' => 'bamah@example.com']);
        self::assertSame('en_attente', $juryA->getStatus()->value);
        self::assertSame('en_attente', $juryB->getStatus()->value);
        $juryAId = $juryA->getId();
        $juryBId = $juryB->getId();

        $mailer = static::getContainer()->get(JuryInvitationMailer::class);

        // 3. Les deux jurés acceptent leur invitation — ceci seul ne doit
        // jamais vérifier la soutenance (cahier §19 : accepter ≠ valider).
        $client->request('GET', $mailer->generateDecisionUrl($juryA, 'confirmer'));
        self::assertResponseIsSuccessful();
        $client->request('GET', $mailer->generateDecisionUrl($juryB, 'confirmer'));
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        self::assertSame('confirme', $em->getRepository(JuryMember::class)->find($juryAId)->getStatus()->value);
        self::assertSame('annoncee', $project->getDefense()->getStatus()->value, 'Accepter l\'invitation ne doit pas vérifier la soutenance.');
        self::assertSame('publie', $project->getStatus()->value);

        // 4. La soutenance a lieu : l'étudiant la marque comme réalisée.
        $crawler = $client->request('GET', '/ma-soutenance');
        $token = $crawler->filter('form[action="/ma-soutenance/'.$projectId.'/realisee"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/ma-soutenance/'.$projectId.'/realisee', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('realisee', $em->getRepository(Project::class)->find($projectId)->getDefense()->getStatus()->value);

        // 5. Un seul juré valide que la soutenance a bien eu lieu : toujours pas vérifiée.
        $juryA = $em->getRepository(JuryMember::class)->find($juryAId);
        $client->request('GET', $mailer->generateDecisionUrl($juryA, 'valider'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'enregistrée');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        self::assertSame(1, $project->getDefense()->getValidationCount());
        self::assertSame('realisee', $project->getDefense()->getStatus()->value, 'Une seule validation ne doit jamais suffire.');
        self::assertSame('publie', $project->getStatus()->value);

        // Ce même juré ne peut pas valider une seconde fois.
        $juryA = $em->getRepository(JuryMember::class)->find($juryAId);
        $client->request('GET', $mailer->generateDecisionUrl($juryA, 'valider'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(Project::class)->find($projectId)->getDefense()->getValidationCount(), 'Une double validation par le même juré ne doit pas être comptée deux fois.');

        // 6. Le 2ᵉ juré valide à son tour : le seuil de 2 est atteint.
        $juryB = $em->getRepository(JuryMember::class)->find($juryBId);
        $client->request('GET', $mailer->generateDecisionUrl($juryB, 'valider'));
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        self::assertSame(2, $project->getDefense()->getValidationCount());
        self::assertSame('verifiee', $project->getDefense()->getStatus()->value);
        self::assertSame('verifie', $project->getStatus()->value);
    }


    /**
     * Régression : soumettre le formulaire d'annonce avec des champs vides
     * (date/heure/lieu) faisait planter l'application avec une TypeError
     * (Defense::setDate() attend un DateTimeImmutable, pas null) — le
     * DataMapper de Symfony écrit les valeurs avant que la validation
     * NotBlank ne s'exécute. Les setters doivent tolérer null.
     */
    public function testAnnouncingWithEmptyFieldsFailsGracefullyInsteadOfCrashing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('etudiant3@example.com')
            ->setFirstName('Koffi')
            ->setLastName('Mensah')
            ->setPhone('+22890000002')
            ->setRoles(['ROLE_TALENT'])
            ->setStatus(UserStatus::ACTIF)
            ->setSlug('koffi-mensah')
            ->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        $project = new Project();
        $project->setName('Projet sans soutenance annoncée');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-sans-defense');
        $project->setOwner($user);
        $em->persist($project);
        $em->flush();
        $projectId = $project->getId();

        $client->loginUser($user);

        $crawler = $client->request('GET', '/ma-soutenance');
        $form = $crawler->selectButton('Publier l\'annonce')->form([
            'defense_announce[date]' => '',
            'defense_announce[time]' => '',
            'defense_announce[place]' => '',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/ma-soutenance/'.$projectId);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Merci de renseigner');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->find($projectId);
        self::assertNull($project->getDefense(), 'Aucune soutenance ne doit être créée depuis un formulaire invalide.');
    }

    public function testExpiredOrTamperedJuryLinkIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail('etudiant2@example.com')
            ->setFirstName('Awa')
            ->setLastName('Koffi')
            ->setPhone('+22890000001')
            ->setRoles(['ROLE_USER'])
            ->setSlug('awa-koffi');
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        $project = new Project();
        $project->setName('Projet de test');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-test-2');
        $project->setOwner($user);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate(new \DateTimeImmutable('2026-09-15'));
        $defense->setTime('14:00');
        $defense->setPlace('Amphi B');
        $project->setDefense($defense);
        $em->persist($defense);

        $juryMember = new JuryMember();
        $juryMember->setFirstName('B.');
        $juryMember->setLastName('Amah');
        $juryMember->setRole(\App\Enum\JuryRole::RAPPORTEUR);
        $juryMember->setEmail('bamah@example.com');
        $defense->addJuryMember($juryMember);
        $em->persist($juryMember);
        $em->flush();

        $juryMemberId = $juryMember->getId();
        $client->request('GET', '/jury/confirmer?id='.$juryMemberId.'&expires=9999999999&_hash=invalide');
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $juryMember = $em->getRepository(JuryMember::class)->find($juryMemberId);
        self::assertSame('en_attente', $juryMember->getStatus()->value);
    }
}
