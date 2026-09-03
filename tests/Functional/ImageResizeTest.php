<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §20 : compression et redimensionnement automatique des
 * images téléversées (photo de profil, ici — même mécanisme partagé par
 * les photos de projet et les logos d'établissement).
 */
class ImageResizeTest extends FunctionalTestCase
{
    public function testOversizedAvatarIsResizedDownToMaxDimension(): void
    {
        self::assertTrue(\extension_loaded('gd'), 'Ce test suppose GD activé.');

        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $talent = (new User())
            ->setEmail('gros.avatar@example.com')->setFirstName('Test')->setLastName('Resize')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)
            ->setSlug('gros-avatar')->setEmailVerified(true);
        $talent->setPassword($hasher->hashPassword($talent, 'MotDePasse123'));
        $em->persist($talent);
        $em->flush();

        // Image volontairement plus grande que le maximum (800x800) autorisé pour l'avatar.
        $large = imagecreatetruecolor(1600, 1200);
        imagefill($large, 0, 0, imagecolorallocate($large, 10, 100, 200));
        $source = sys_get_temp_dir().'/moumtou-large-avatar.jpg';
        imagejpeg($large, $source, 90);
        imagedestroy($large);
        self::assertSame([1600, 1200], [imagesx(imagecreatefromjpeg($source)), imagesy(imagecreatefromjpeg($source))]);

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Test',
            'profile_edit[lastName]' => 'Resize',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
        ]);
        $form['profile_edit[photo]']->upload($source);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        $storedPath = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$refreshed->getPhoto();
        self::assertFileExists($storedPath);

        [$storedWidth, $storedHeight] = getimagesize($storedPath);
        self::assertLessThanOrEqual(800, $storedWidth, 'La largeur doit avoir été réduite à 800px maximum.');
        self::assertLessThanOrEqual(800, $storedHeight);
        self::assertLessThan(1600, $storedWidth, 'L\'image doit avoir été réellement redimensionnée, pas simplement copiée.');
    }

    public function testSmallImageIsNotUpscaled(): void
    {
        self::assertTrue(\extension_loaded('gd'));

        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $talent = (new User())
            ->setEmail('petit.avatar@example.com')->setFirstName('Test')->setLastName('Small')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)
            ->setSlug('petit-avatar')->setEmailVerified(true);
        $talent->setPassword($hasher->hashPassword($talent, 'MotDePasse123'));
        $em->persist($talent);
        $em->flush();

        $small = imagecreatetruecolor(50, 50);
        $source = sys_get_temp_dir().'/moumtou-small-avatar.png';
        imagepng($small, $source);
        imagedestroy($small);

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Test',
            'profile_edit[lastName]' => 'Small',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
        ]);
        $form['profile_edit[photo]']->upload($source);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(User::class)->find($talent->getId());
        $storedPath = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$refreshed->getPhoto();

        [$storedWidth, $storedHeight] = getimagesize($storedPath);
        self::assertSame(50, $storedWidth, 'Une image déjà petite ne doit jamais être agrandie.');
        self::assertSame(50, $storedHeight);
    }

    public function testProfessionalTitleIsSavedAndDisplayedPublicly(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $talent = (new User())
            ->setEmail('titre.pro@example.com')->setFirstName('Jean')->setLastName('Kossi')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)
            ->setSlug('jean-kossi')->setEmailVerified(true);
        $talent->setPassword($hasher->hashPassword($talent, 'MotDePasse123'));
        $em->persist($talent);
        $em->flush();

        $client->loginUser($talent);
        $crawler = $client->request('GET', '/mon-profil/modifier');
        $form = $crawler->selectButton('Enregistrer')->form([
            'profile_edit[firstName]' => 'Jean',
            'profile_edit[lastName]' => 'Kossi',
            'profile_edit[professionalTitle]' => 'Développeur Full Stack',
            'profile_edit[phone]' => '+22890000000',
            'profile_edit[whatsappEnabled]' => '0',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Développeur Full Stack', $em->getRepository(User::class)->find($talent->getId())->getProfessionalTitle());

        $client->request('GET', '/profils/jean-kossi');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Développeur Full Stack');
        self::assertStringContainsString('Jean Kossi — Développeur Full Stack — MOUMTOU', $client->getResponse()->getContent());
    }
}
