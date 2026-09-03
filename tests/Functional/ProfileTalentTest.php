<?php

namespace App\Tests\Functional;

use App\Entity\Skill;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Profil Talent : informations personnelles, photo, bio, compétences,
 * technologies, liens professionnels, WhatsApp, permissions, multi-rôle.
 */
class ProfileTalentTest extends FunctionalTestCase
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

    // ---- Bio / liens : validation serveur -----------------------------

    public function testBioExceedingMaxLengthIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'bio.trop.longue@example.com', ['ROLE_TALENT'], 'bio-longue');
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Test',
            'profile_edit[lastName]' => 'Bio',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
            'profile_edit[bio]' => str_repeat('a', 2001),
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ne doit pas dépasser');
    }

    public function testNonLinkedinUrlIsRejectedAsLinkedinLink(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'faux.linkedin@example.com', ['ROLE_TALENT'], 'faux-linkedin');
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Test',
            'profile_edit[lastName]' => 'Linkedin',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
            'profile_edit[linkedinUrl]' => 'https://facebook.com/jean.dupont',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'profil LinkedIn');
    }

    public function testValidProfileUpdateIsSaved(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $skill = new Skill();
        $skill->setName('Développement web');
        $em->persist($skill);

        $talent = $this->makeUser($em, 'valid.update@example.com', ['ROLE_TALENT'], 'valid-update');
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Jean',
            'profile_edit[lastName]' => 'Kossi',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
            'profile_edit[bio]' => 'Développeur passionné.',
            'profile_edit[linkedinUrl]' => 'https://www.linkedin.com/in/jeankossi',
            'profile_edit[githubUrl]' => 'https://github.com/jeankossi',
            'profile_edit[skills]' => [(string) $skill->getId()],
            'profile_edit[technologiesInput]' => 'Java,Angular',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        self::assertSame('Jean', $refreshed->getFirstName());
        self::assertSame('Développeur passionné.', $refreshed->getBio());
        self::assertCount(1, $refreshed->getSkills());
        self::assertCount(2, $refreshed->getTechnologies());

        // Affichage public.
        $client->request('GET', '/profils/valid-update');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Développeur passionné.');
        self::assertSelectorExists('a[href="https://www.linkedin.com/in/jeankossi"]');
    }

    // ---- Photo ----------------------------------------------------------

    public function testPhotoUploadReplaceAndDelete(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'photo.talent@example.com', ['ROLE_TALENT'], 'photo-talent');
        $em->flush();

        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $source = sys_get_temp_dir().'/moumtou-profile-photo.png';
        file_put_contents($source, $pngBytes);

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Test',
            'profile_edit[lastName]' => 'Photo',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
        ]);
        $form['profile_edit[photo]']->upload($source);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        self::assertNotNull($refreshed->getPhoto());
        $photoPath = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$refreshed->getPhoto();
        self::assertFileExists($photoPath);

        // Suppression.
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $token = $crawler->filter('form[action="/mon-profil/photo/supprimer"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/mon-profil/photo/supprimer', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/mon-profil/modifier');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        self::assertNull($refreshed->getPhoto());
        self::assertFileDoesNotExist($photoPath, 'Le fichier physique doit être supprimé du disque.');
    }

    public function testInvalidPhotoFormatIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talent = $this->makeUser($em, 'photo.invalide@example.com', ['ROLE_TALENT'], 'photo-invalide');
        $em->flush();

        $source = sys_get_temp_dir().'/moumtou-profile-photo.txt';
        file_put_contents($source, 'not an image');

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Test',
            'profile_edit[lastName]' => 'Photo',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
        ]);
        $form['profile_edit[photo]']->upload($source);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Formats acceptés');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(User::class)->find($talent->getId())->getPhoto());
    }

    // ---- WhatsApp ---------------------------------------------------------

    public function testWhatsappButtonVisibilityFollowsOptIn(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $enabled = $this->makeUser($em, 'wa.on@example.com', ['ROLE_TALENT'], 'wa-on');
        $enabled->setWhatsapp('22890000099')->setWhatsappEnabled(true);
        $disabled = $this->makeUser($em, 'wa.off@example.com', ['ROLE_TALENT'], 'wa-off');
        $disabled->setWhatsapp('22890000098')->setWhatsappEnabled(false);
        $em->flush();

        $client->request('GET', '/profils/wa-on');
        self::assertSelectorExists('a[href="https://wa.me/22890000099"]');

        $crawler = $client->request('GET', '/profils/wa-off');
        // Le bouton "Partager via WhatsApp" (générique, sans numéro) reste
        // légitime ; seul le bouton de contact direct doit être absent.
        self::assertSelectorNotExists('a[href="https://wa.me/22890000098"]');
        self::assertStringNotContainsString('22890000098', $client->getResponse()->getContent(), 'Le numéro ne doit jamais apparaître publiquement si le contact WhatsApp est désactivé.');
    }

    // ---- Permissions ------------------------------------------------------

    public function testUserCannotEditAnotherUsersProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talentA = $this->makeUser($em, 'talent.a@example.com', ['ROLE_TALENT'], 'talent-a-profil');
        $talentB = $this->makeUser($em, 'talent.b@example.com', ['ROLE_TALENT'], 'talent-b-profil');
        $em->flush();

        // /mon-profil/modifier n'a pas de paramètre d'identifiant : il opère
        // toujours sur l'utilisateur connecté, jamais sur un profil arbitraire.
        $client->loginUser($talentA);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Usurpation',
            'profile_edit[lastName]' => 'Tentative',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Usurpation', $em->getRepository(User::class)->find($talentA->getId())->getFirstName());
        self::assertSame('Test', $em->getRepository(User::class)->find($talentB->getId())->getFirstName(), 'Le profil de B ne doit pas avoir été modifié.');
    }

    // ---- Multi-rôle ---------------------------------------------------------

    public function testProfilePageWorksForEveryMultiRoleCombination(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $talentOnly = $this->makeUser($em, 'combo.talent@example.com', ['ROLE_TALENT'], 'combo-talent');
        $talentTeacher = $this->makeUser($em, 'combo.talent.teacher@example.com', ['ROLE_TALENT', 'ROLE_TEACHER'], 'combo-talent-teacher');
        $talentRecruiter = $this->makeUser($em, 'combo.talent.recruiter@example.com', ['ROLE_TALENT', 'ROLE_RECRUITER'], 'combo-talent-recruiter');
        $em->flush();

        foreach ([$talentOnly, $talentTeacher, $talentRecruiter] as $user) {
            $client->request('GET', '/profils/'.$user->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $user->getFullName());
        }

        // Le compte cumulant Talent+Enseignant garde bien accès aux deux espaces.
        $client->loginUser($talentTeacher);
        $client->request('GET', '/mon-profil/modifier');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/mon-espace-enseignant');
        self::assertResponseIsSuccessful();
    }
}
