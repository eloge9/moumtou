<?php

namespace App\Tests\Functional;

use App\Entity\Institution;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recherche d'un enseignant déjà inscrit pour l'inviter au jury, plutôt que
 * de toujours ressaisir son nom à la main.
 */
class JuryMemberSearchTest extends FunctionalTestCase
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

    public function testSearchFindsTeacherByNameAndExcludesNonTeachers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'candidat@example.com', 'candidat');
        $teacher = $this->createUser($em, 'prof.dupont@example.com', 'prof-dupont', ['ROLE_TALENT', 'ROLE_TEACHER']);
        $teacher->setFirstName('Jean')->setLastName('Dupont');
        $otherTalent = $this->createUser($em, 'autre.talent@example.com', 'autre-talent');
        $otherTalent->setFirstName('Jean')->setLastName('Sansrole');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/ma-soutenance/jury/rechercher', ['q' => 'Jean']);
        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $emails = array_column($data['results'], 'email');
        self::assertContains('prof.dupont@example.com', $emails, 'Un compte ROLE_TEACHER correspondant au nom doit apparaître.');
        self::assertNotContains('autre.talent@example.com', $emails, 'Un compte sans ROLE_TEACHER ne doit jamais apparaître, même si le nom correspond.');
    }

    public function testSearchExcludesSelfAndInactiveAccounts(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talentTeacher = $this->createUser($em, 'moi-enseignant@example.com', 'moi-enseignant', ['ROLE_TALENT', 'ROLE_TEACHER']);
        $talentTeacher->setFirstName('Auto')->setLastName('Invite');
        $suspended = $this->createUser($em, 'suspendu@example.com', 'suspendu-teacher', ['ROLE_TALENT', 'ROLE_TEACHER']);
        $suspended->setFirstName('Auto')->setLastName('Suspendu')->setStatus(UserStatus::SUSPENDU);
        $em->flush();

        $client->loginUser($talentTeacher);
        $client->request('GET', '/ma-soutenance/jury/rechercher', ['q' => 'Auto']);
        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $emails = array_column($data['results'], 'email');
        self::assertNotContains('moi-enseignant@example.com', $emails, 'Le compte connecté ne doit jamais se proposer lui-même.');
        self::assertNotContains('suspendu@example.com', $emails, 'Un compte suspendu ne doit jamais être proposé.');
    }

    public function testSearchFiltersByInstitution(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'candidat2@example.com', 'candidat2');
        $institutionA = (new Institution())->setName('Institution A')->setCountry('Togo')->setCity('Lomé')->setVerified(true);
        $institutionB = (new Institution())->setName('Institution B')->setCountry('Togo')->setCity('Kara')->setVerified(true);
        $em->persist($institutionA);
        $em->persist($institutionB);

        $teacherA = $this->createUser($em, 'teacher.a@example.com', 'teacher-a', ['ROLE_TALENT', 'ROLE_TEACHER']);
        $teacherA->setFirstName('Marie')->setLastName('A')->setInstitution($institutionA);
        $teacherB = $this->createUser($em, 'teacher.b@example.com', 'teacher-b', ['ROLE_TALENT', 'ROLE_TEACHER']);
        $teacherB->setFirstName('Marie')->setLastName('B')->setInstitution($institutionB);
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/ma-soutenance/jury/rechercher', ['q' => 'Marie', 'institution' => (string) $institutionA->getId()]);
        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $emails = array_column($data['results'], 'email');
        self::assertContains('teacher.a@example.com', $emails);
        self::assertNotContains('teacher.b@example.com', $emails, 'Le filtre établissement doit exclure les enseignants d\'un autre établissement.');
    }

    public function testSearchRequiresAtLeastTwoCharactersWithoutInstitutionFilter(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->createUser($em, 'candidat3@example.com', 'candidat3');
        $em->flush();

        $client->loginUser($talent);
        $client->request('GET', '/ma-soutenance/jury/rechercher', ['q' => 'J']);
        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    /** Sécurité : réservé à un compte connecté (ROLE_TALENT au minimum). */
    public function testAnonymousCannotUseTheSearchEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ma-soutenance/jury/rechercher', ['q' => 'Test']);
        self::assertResponseRedirects();
    }
}
