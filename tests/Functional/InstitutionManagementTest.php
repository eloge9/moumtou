<?php

namespace App\Tests\Functional;

use App\Entity\Defense;
use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\InstitutionRequest;
use App\Entity\JuryMember;
use App\Entity\Mention;
use App\Entity\Project;
use App\Entity\Specialty;
use App\Entity\User;
use App\Entity\UserInstitution;
use App\Enum\InstitutionContext;
use App\Enum\InstitutionRequestStatus;
use App\Enum\InstitutionType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gestion des établissements et rattachement aux utilisateurs : couvre la
 * matrice de tests demandée (établissement, étudiant, enseignant, jury,
 * multi-rôle, sécurité).
 */
class InstitutionManagementTest extends FunctionalTestCase
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

    // ---- Établissement (admin) ------------------------------------------

    public function testAdminCanCreateEditVerifyAndDeactivateInstitution(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.inst@example.com', ['ROLE_ADMIN'], 'admin-inst');
        $em->flush();
        $client->loginUser($admin);

        // Création
        $crawler = $client->request('GET', '/admin/etablissements');
        $token = $crawler->filter('form[action="/admin/etablissements/ajouter"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/etablissements/ajouter', [
            'name' => 'IPNET Institute of Technology',
            'type' => 'institut',
            'country' => 'Togo',
            'city' => 'Lomé',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/etablissements');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $institution = $em->getRepository(Institution::class)->findOneBy(['name' => 'IPNET Institute of Technology']);
        self::assertNotNull($institution);
        self::assertSame(InstitutionType::INSTITUT, $institution->getType());
        self::assertTrue($institution->isVerified());
        self::assertTrue($institution->isActive());
        $institutionId = $institution->getId();

        // Modification
        $crawler = $client->request('GET', '/admin/etablissements');
        $token = $crawler->filter('form[action="/admin/etablissements/'.$institutionId.'/modifier"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/etablissements/'.$institutionId.'/modifier', [
            'name' => 'IPNET Institute of Technology',
            'type' => 'institut',
            'city' => 'Lomé-Bè',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/etablissements');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $institution = $em->getRepository(Institution::class)->find($institutionId);
        self::assertSame('Lomé-Bè', $institution->getCity());
        self::assertNotNull($institution->getUpdatedAt());

        // Désactivation
        $crawler = $client->request('GET', '/admin/etablissements');
        $token = $crawler->filter('form[action="/admin/etablissements/'.$institutionId.'/desactiver"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/etablissements/'.$institutionId.'/desactiver', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/admin/etablissements');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertFalse($em->getRepository(Institution::class)->find($institutionId)->isActive());
    }

    public function testNonAdminCannotManageInstitutions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'talent.noadmin@example.com', ['ROLE_TALENT'], 'talent-noadmin');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/admin/etablissements');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/etablissements/demandes');
        self::assertResponseStatusCodeSame(403);
    }

    // ---- Étudiant (rattachement + formation) -----------------------------

    public function testTalentCanSetInstitutionAndAcademicInfoOnProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $institution = new Institution();
        $institution->setName('Université de Lomé')->setType(InstitutionType::UNIVERSITE)->setVerified(true);
        $domain = new Domain();
        $domain->setName('Sciences et Technologies');
        $mention = new Mention();
        $mention->setName('Informatique')->setDomain($domain);
        $specialty = new Specialty();
        $specialty->setName('Génie Logiciel')->setMention($mention);
        $em->persist($institution);
        $em->persist($domain);
        $em->persist($mention);
        $em->persist($specialty);

        $talent = $this->makeUser($em, 'etudiant.profil@example.com', ['ROLE_TALENT'], 'etudiant-profil');
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Jean',
            'profile_edit[lastName]' => 'Dupont',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
            'profile_edit[institution]' => (string) $institution->getId(),
            'profile_edit[domain]' => (string) $domain->getId(),
            'profile_edit[mention]' => (string) $mention->getId(),
            'profile_edit[specialty]' => (string) $specialty->getId(),
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        self::assertSame('Université de Lomé', $refreshed->getInstitution()->getName());
        self::assertSame('Génie Logiciel', $refreshed->getSpecialty()->getName());

        // Le rattachement multi-établissement est bien alimenté en parallèle.
        $attachment = $em->getRepository(UserInstitution::class)->findOneBy(['user' => $refreshed]);
        self::assertNotNull($attachment);
        self::assertSame(InstitutionContext::ETUDIANT, $attachment->getContext());
    }

    public function testTalentCanRequestAMissingInstitutionAndAdminCanAcceptIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'demandeur@example.com', ['ROLE_TALENT'], 'demandeur');
        $admin = $this->makeUser($em, 'admin.demande@example.com', ['ROLE_ADMIN'], 'admin-demande');
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $token = $crawler->filter('form[action="/etablissements/demander"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/etablissements/demander', [
            'name' => 'IPNET Institute of Technology',
            'type' => 'institut',
            'country' => 'Togo',
            'city' => 'Lomé',
            'redirect' => '/mon-profil/modifier',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/mon-profil/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $institutionRequest = $em->getRepository(InstitutionRequest::class)->findOneBy(['name' => 'IPNET Institute of Technology']);
        self::assertNotNull($institutionRequest);
        self::assertSame(InstitutionRequestStatus::EN_ATTENTE, $institutionRequest->getStatus());
        self::assertNull($em->getRepository(Institution::class)->findOneBy(['name' => 'IPNET Institute of Technology']), 'La demande ne doit pas créer automatiquement un établissement.');
        $requestId = $institutionRequest->getId();

        // L'admin accepte.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/etablissements/demandes');
        $token = $crawler->filter('form[action="/admin/etablissements/demandes/'.$requestId.'/decider"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/etablissements/demandes/'.$requestId.'/decider', [
            'decision' => 'accepter',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/etablissements/demandes');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $decided = $em->getRepository(InstitutionRequest::class)->find($requestId);
        self::assertSame(InstitutionRequestStatus::ACCEPTEE, $decided->getStatus());
        self::assertNotNull($decided->getCreatedInstitution());
        self::assertTrue($decided->getCreatedInstitution()->isVerified());
    }

    // ---- Enseignant (multi-rattachement) --------------------------------

    public function testTeacherCanAttachToMultipleInstitutionsAndRemoveOne(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $ipnet = (new Institution())->setName('IPNET')->setType(InstitutionType::INSTITUT)->setVerified(true);
        $ul = (new Institution())->setName('Université de Lomé')->setType(InstitutionType::UNIVERSITE)->setVerified(true);
        $em->persist($ipnet);
        $em->persist($ul);

        $teacher = $this->makeUser($em, 'prof.multi@example.com', ['ROLE_TEACHER'], 'prof-multi');
        $em->flush();

        $client->loginUser($teacher);
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');

        $client->request('POST', '/mon-espace-enseignant/institution', ['institution' => $ipnet->getId(), '_csrf_token' => $token]);
        self::assertResponseRedirects('/mon-espace-enseignant');

        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $token = $crawler->filter('form[action="/mon-espace-enseignant/institution"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/mon-espace-enseignant/institution', ['institution' => $ul->getId(), '_csrf_token' => $token]);
        self::assertResponseRedirects('/mon-espace-enseignant');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshedTeacher = $em->getRepository(User::class)->find($teacher->getId());
        $attachments = $em->getRepository(UserInstitution::class)->findBy(['user' => $refreshedTeacher, 'active' => true]);
        self::assertCount(2, $attachments);

        // Retirer un rattachement.
        $crawler = $client->request('GET', '/mon-espace-enseignant');
        $attachmentId = $attachments[0]->getId();
        $token = $crawler->filter('form[action="/mon-espace-enseignant/institution/'.$attachmentId.'/retirer"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/mon-espace-enseignant/institution/'.$attachmentId.'/retirer', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/mon-espace-enseignant');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(UserInstitution::class)->findBy(['user' => $refreshedTeacher, 'active' => true]));
    }

    // ---- Jury : établissement propre au membre, distinct de l'étudiant --

    public function testJuryMemberCanBeAssignedACatalogInstitutionDifferentFromStudents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $studentInstitution = (new Institution())->setName('IPNET')->setType(InstitutionType::INSTITUT)->setVerified(true);
        $juryInstitution = (new Institution())->setName('Université de Lomé')->setType(InstitutionType::UNIVERSITE)->setVerified(true);
        $em->persist($studentInstitution);
        $em->persist($juryInstitution);

        $talent = $this->makeUser($em, 'etudiant.jury@example.com', ['ROLE_TALENT'], 'etudiant-jury');
        $talent->setInstitution($studentInstitution);

        $project = new Project();
        $project->setName('Soutenance avec jury externe');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('soutenance-jury-externe');
        $project->setOwner($talent);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate(new \DateTimeImmutable('2026-11-01'));
        $defense->setTime('10:00');
        $defense->setPlace('Amphi C');
        $project->setDefense($defense);
        $em->persist($defense);
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/ma-soutenance');
        $form = $crawler->selectButton('+ Inviter')->form([
            'jury_invite[firstName]' => 'Prof.',
            'jury_invite[lastName]' => 'Externe',
            'jury_invite[role]' => 'rapporteur',
            'jury_invite[institution]' => (string) $juryInstitution->getId(),
            'jury_invite[email]' => 'prof.externe@example.com',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ma-soutenance/'.$project->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $juryMember = $em->getRepository(JuryMember::class)->findOneBy(['email' => 'prof.externe@example.com']);
        self::assertNotNull($juryMember);
        self::assertSame('Université de Lomé', $juryMember->getInstitution()->getName());
        self::assertSame('Université de Lomé', $juryMember->getInstitutionLabel());
        self::assertNotSame($juryMember->getInstitution()->getId(), $talent->getInstitution()->getId(), 'Le jury doit pouvoir appartenir à un établissement différent de celui de l\'étudiant.');
    }

    // ---- Multi-rôle : un compte Talent invité au jury devient aussi Enseignant

    public function testInvitingAnExistingTalentAsJuryMemberGrantsTeacherRoleAdditively(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'porteur.multirole@example.com', ['ROLE_TALENT'], 'porteur-multirole');
        // Ce compte est un Talent qui va aussi être invité comme membre du jury.
        $bothRoles = $this->makeUser($em, 'double.role@example.com', ['ROLE_TALENT'], 'double-role');

        $project = new Project();
        $project->setName('Soutenance multi-rôle');
        $project->setType(ProjectType::SOUTENANCE);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('soutenance-multi-role');
        $project->setOwner($owner);
        $em->persist($project);

        $defense = new Defense();
        $defense->setProject($project);
        $defense->setDate(new \DateTimeImmutable('2026-11-05'));
        $defense->setTime('09:00');
        $defense->setPlace('Amphi D');
        $project->setDefense($defense);
        $em->persist($defense);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/ma-soutenance');
        $form = $crawler->selectButton('+ Inviter')->form([
            'jury_invite[firstName]' => 'Double',
            'jury_invite[lastName]' => 'Role',
            'jury_invite[role]' => 'examinateur',
            'jury_invite[email]' => 'double.role@example.com',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ma-soutenance/'.$project->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($bothRoles->getId());
        self::assertContains('ROLE_TALENT', $refreshed->getRoles(), 'Le rôle existant ne doit jamais être retiré.');
        self::assertContains('ROLE_TEACHER', $refreshed->getRoles(), 'ROLE_TEACHER doit être accordé en plus, pas en remplacement.');

        // Ce compte doit maintenant accéder aux deux espaces.
        $client->loginUser($refreshed);
        $client->request('GET', '/publier');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/mon-espace-enseignant');
        self::assertResponseIsSuccessful();
    }
}
