<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\JuryMember;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\DefenseStatus;
use App\Enum\JuryRole;
use App\Enum\JuryStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Résultat de soutenance : saisie réservée au jury confirmé, validation
 * réservée au président/admin, indépendance stricte entre vérification de
 * la soutenance (DEFENSE_VERIFIED) et validation du résultat (RESULT_VALIDATED).
 */
class DefenseResultTest extends FunctionalTestCase
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

    /**
     * @return array{0: Project, 1: Defense, 2: JuryMember, 3: JuryMember}
     */
    private function makeRealizedDefenseWithTwoConfirmedJurors(EntityManagerInterface $em, User $owner, User $president, User $rapporteur, string $slug): array
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
        $defense->setDate(new \DateTimeImmutable('2026-10-01'));
        $defense->setTime('10:00');
        $defense->setPlace('Amphi A');
        $defense->setStatus(DefenseStatus::REALISEE);
        $project->setDefense($defense);
        $em->persist($defense);

        $juryPresident = new JuryMember();
        $juryPresident->setFirstName('P')->setLastName('President')->setEmail($president->getEmail());
        $juryPresident->setRole(JuryRole::PRESIDENT);
        $juryPresident->setStatus(JuryStatus::CONFIRME);
        $juryPresident->setInvitedUser($president);
        $defense->addJuryMember($juryPresident);
        $em->persist($juryPresident);

        $juryRapporteur = new JuryMember();
        $juryRapporteur->setFirstName('R')->setLastName('Rapporteur')->setEmail($rapporteur->getEmail());
        $juryRapporteur->setRole(JuryRole::RAPPORTEUR);
        $juryRapporteur->setStatus(JuryStatus::CONFIRME);
        $juryRapporteur->setInvitedUser($rapporteur);
        $defense->addJuryMember($juryRapporteur);
        $em->persist($juryRapporteur);

        $em->flush();

        return [$project, $defense, $juryPresident, $juryRapporteur];
    }

    public function testConfirmedJuryMemberCanSubmitAResult(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.result1@example.com', ['ROLE_TALENT'], 'owner-result1');
        $president = $this->makeUser($em, 'president.result1@example.com', ['ROLE_TEACHER'], 'president-result1');
        $rapporteur = $this->makeUser($em, 'rapporteur.result1@example.com', ['ROLE_TEACHER'], 'rapporteur-result1');
        [, $defense] = $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-resultat-1');
        $defenseId = $defense->getId();

        $client->loginUser($rapporteur);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', [
            'grade' => '16.5',
            'status' => 'reussie',
            'decision' => 'admis',
            'appreciation' => 'Travail sérieux et pertinent.',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $result = $em->getRepository(Defense::class)->find($defenseId)->getAcademicResult();
        self::assertNotNull($result);
        self::assertSame(16.5, $result->getGrade());
        self::assertSame('reussie', $result->getStatus()->value);
        self::assertSame('admis', $result->getDecision()->value);
        self::assertFalse($result->isValidated(), 'La saisie seule ne doit jamais valider le résultat.');
    }

    public function testGradeOutsideRangeIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.result2@example.com', ['ROLE_TALENT'], 'owner-result2');
        $president = $this->makeUser($em, 'president.result2@example.com', ['ROLE_TEACHER'], 'president-result2');
        $rapporteur = $this->makeUser($em, 'rapporteur.result2@example.com', ['ROLE_TEACHER'], 'rapporteur-result2');
        [, $defense] = $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-resultat-2');
        $defenseId = $defense->getId();

        $client->loginUser($president);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', [
            'grade' => '25',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'comprise entre 0 et 20');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Defense::class)->find($defenseId)->getAcademicResult());
    }

    public function testNonJuryMemberCannotSubmitAResult(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.result3@example.com', ['ROLE_TALENT'], 'owner-result3');
        $president = $this->makeUser($em, 'president.result3@example.com', ['ROLE_TEACHER'], 'president-result3');
        $rapporteur = $this->makeUser($em, 'rapporteur.result3@example.com', ['ROLE_TEACHER'], 'rapporteur-result3');
        [, $defense] = $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-resultat-3');
        $defenseId = $defense->getId();

        $outsider = $this->makeUser($em, 'outsider.result3@example.com', ['ROLE_TEACHER'], 'outsider-result3');
        $em->flush();

        $client->loginUser($outsider);
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', [
            'grade' => '15',
            '_csrf_token' => 'peu-importe',
        ]);
        self::assertResponseStatusCodeSame(403);

        // Le candidat lui-même ne peut pas non plus s'auto-attribuer un résultat.
        $client->loginUser($owner);
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', [
            'grade' => '20',
            '_csrf_token' => 'peu-importe',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyPresidentOrAdminCanValidateTheResult(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.result4@example.com', ['ROLE_TALENT'], 'owner-result4');
        $president = $this->makeUser($em, 'president.result4@example.com', ['ROLE_TEACHER'], 'president-result4');
        $rapporteur = $this->makeUser($em, 'rapporteur.result4@example.com', ['ROLE_TEACHER'], 'rapporteur-result4');
        [, $defense] = $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-resultat-4');
        $defenseId = $defense->getId();

        // Le rapporteur saisit le résultat.
        $client->loginUser($rapporteur);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', ['grade' => '14', '_csrf_token' => $token]);
        self::assertResponseRedirects();

        // Le rapporteur (non président) ne peut pas valider.
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        self::assertSelectorNotExists('form[action="/soutenances/'.$defenseId.'/resultat/valider"]', 'Un non-président ne doit pas voir le bouton de validation.');

        $client->request('POST', '/soutenances/'.$defenseId.'/resultat/valider', ['_csrf_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(403);

        // Le président peut valider.
        $client->loginUser($president);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat/valider"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat/valider', ['_csrf_token' => $token]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $result = $em->getRepository(Defense::class)->find($defenseId)->getAcademicResult();
        self::assertTrue($result->isValidated());
        self::assertSame('president-result4', $result->getValidatedBy()->getSlug());
    }

    public function testDefenseVerificationAndResultValidationAreIndependent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.result5@example.com', ['ROLE_TALENT'], 'owner-result5');
        $president = $this->makeUser($em, 'president.result5@example.com', ['ROLE_TEACHER'], 'president-result5');
        $rapporteur = $this->makeUser($em, 'rapporteur.result5@example.com', ['ROLE_TEACHER'], 'rapporteur-result5');
        [$project, $defense, $juryPresident] = $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-resultat-5');
        $defenseId = $defense->getId();

        // Valider le résultat sans jamais vérifier la soutenance (aucune DefenseValidation créée).
        $client->loginUser($president);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', ['grade' => '12', 'status' => 'reussie', '_csrf_token' => $token]);

        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat/valider"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat/valider', ['_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Defense::class)->find($defenseId);
        self::assertTrue($refreshed->getAcademicResult()->isValidated(), 'Le résultat est validé...');
        self::assertSame('realisee', $refreshed->getStatus()->value, '...mais la soutenance elle-même ne doit pas être considérée comme vérifiée pour autant.');
    }

    public function testResultVisibilityIsHiddenByDefaultAndControlledByTheOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.result6@example.com', ['ROLE_TALENT'], 'owner-result6');
        $president = $this->makeUser($em, 'president.result6@example.com', ['ROLE_TEACHER'], 'president-result6');
        $rapporteur = $this->makeUser($em, 'rapporteur.result6@example.com', ['ROLE_TEACHER'], 'rapporteur-result6');
        [$project, $defense] = $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-resultat-6');
        $defenseId = $defense->getId();
        $projectId = $project->getId();

        $client->loginUser($rapporteur);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', ['grade' => '17', 'status' => 'reussie', '_csrf_token' => $token]);

        // Masqué par défaut sur la page publique.
        $client->request('GET', '/soutenances/projet-resultat-6');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', '17/20');

        // Le candidat active la visibilité.
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/ma-soutenance');
        $token = $crawler->filter('form[action="/ma-soutenance/'.$projectId.'/resultat/visibilite"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/ma-soutenance/'.$projectId.'/resultat/visibilite', [
            'result_visible' => '1',
            'grade_visible' => '1',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/ma-soutenance/'.$projectId);

        $client->request('GET', '/soutenances/projet-resultat-6');
        self::assertSelectorTextContains('body', '17/20');
    }

    /**
     * Dès la validation finale (président/admin), le résultat devient
     * visible par défaut sur la page publique — le candidat garde la main
     * pour le masquer ensuite (ex. en cas d'échec), mais n'a plus besoin de
     * l'activer lui-même pour un résultat simplement réussi.
     */
    public function testResultBecomesPubliclyVisibleByDefaultOnceValidated(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.result7@example.com', ['ROLE_TALENT'], 'owner-result7');
        $president = $this->makeUser($em, 'president.result7@example.com', ['ROLE_TEACHER'], 'president-result7');
        $rapporteur = $this->makeUser($em, 'rapporteur.result7@example.com', ['ROLE_TEACHER'], 'rapporteur-result7');
        [$project, $defense] = $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-resultat-7');
        $defenseId = $defense->getId();

        $client->loginUser($rapporteur);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat', ['grade' => '18', 'status' => 'reussie', 'appreciation' => 'Excellent travail.', '_csrf_token' => $token]);

        // Toujours masqué avant validation (comportement inchangé).
        $client->request('GET', '/soutenances/projet-resultat-7');
        self::assertSelectorTextNotContains('body', '18/20');

        $client->loginUser($president);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/soutenances/'.$defenseId.'/resultat/valider"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/soutenances/'.$defenseId.'/resultat/valider', ['_csrf_token' => $token]);
        self::assertResponseRedirects();

        // Visible sans que le candidat n'ait rien eu à activer.
        $client->request('GET', '/soutenances/projet-resultat-7');
        self::assertSelectorTextContains('body', '18/20');
        self::assertSelectorTextContains('body', 'Excellent travail.');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $result = $em->getRepository(Defense::class)->find($defenseId)->getAcademicResult();
        self::assertTrue($result->isResultVisible());
        self::assertTrue($result->isGradeVisible());
        self::assertTrue($result->isAppreciationVisible());
    }

    public function testPublicDefensePageIsAccessibleWithoutLoginAndListsJury(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.public@example.com', ['ROLE_TALENT'], 'owner-public');
        $president = $this->makeUser($em, 'president.public@example.com', ['ROLE_TEACHER'], 'president-public');
        $rapporteur = $this->makeUser($em, 'rapporteur.public@example.com', ['ROLE_TEACHER'], 'rapporteur-public');
        $this->makeRealizedDefenseWithTwoConfirmedJurors($em, $owner, $president, $rapporteur, 'projet-public-defense');

        $client->request('GET', '/soutenances/projet-public-defense');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Projet projet-public-defense');
        self::assertSelectorTextContains('body', 'President');
        self::assertSelectorTextContains('body', 'Rapporteur');
    }
}
