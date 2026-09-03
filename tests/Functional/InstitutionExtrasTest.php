<?php

namespace App\Tests\Functional;

use App\Entity\Institution;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Compléments demandés après le rapport initial : filtre établissement
 * dans la recherche recruteur, et upload de logo d'établissement (admin).
 */
class InstitutionExtrasTest extends FunctionalTestCase
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

    public function testRecruiterCanFilterTalentsByInstitution(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $ipnet = (new Institution())->setName('IPNET')->setType(InstitutionType::INSTITUT)->setVerified(true);
        $ul = (new Institution())->setName('Université de Lomé')->setType(InstitutionType::UNIVERSITE)->setVerified(true);
        $em->persist($ipnet);
        $em->persist($ul);

        $talentIpnet = $this->makeUser($em, 'talent.ipnet@example.com', ['ROLE_TALENT'], 'talent-ipnet');
        $talentIpnet->setInstitution($ipnet);
        $talentUl = $this->makeUser($em, 'talent.ul@example.com', ['ROLE_TALENT'], 'talent-ul');
        $talentUl->setInstitution($ul);
        $recruiter = $this->makeUser($em, 'recruteur.filtre@example.com', ['ROLE_RECRUITER'], 'recruteur-filtre');

        foreach ([$talentIpnet, $talentUl] as $i => $owner) {
            $project = new Project();
            $project->setName('Projet '.$i);
            $project->setType(ProjectType::PERSONNEL);
            $project->setStatus(ProjectStatus::PUBLIE);
            $project->setSlug('projet-filtre-'.$i);
            $project->setOwner($owner);
            $em->persist($project);
        }
        $em->flush();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/recruteur?institution='.$ipnet->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '1 talent');

        $names = $crawler->filter('.m-carte a.fw-bold')->each(fn ($node) => $node->text());
        self::assertCount(1, $names);
        self::assertSame('Test Talent-ipnet', $names[0]);
    }

    public function testAdminCanUploadInstitutionLogo(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.logo@example.com', ['ROLE_ADMIN'], 'admin-logo');
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/etablissements');
        $token = $crawler->filter('form[action="/admin/etablissements/ajouter"] input[name="_csrf_token"]')->attr('value');

        // 1x1 PNG transparent minimal, écrit en dur pour ne pas dépendre de l'extension GD.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $sourceImage = sys_get_temp_dir().'/moumtou-test-logo.png';
        file_put_contents($sourceImage, $pngBytes);
        $uploadedFile = new UploadedFile($sourceImage, 'logo.png', 'image/png', null, true);

        $client->request('POST', '/admin/etablissements/ajouter', [
            'name' => 'École avec logo',
            'type' => 'ecole',
            '_csrf_token' => $token,
        ], ['logo' => $uploadedFile]);
        self::assertResponseRedirects('/admin/etablissements');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $institution = $em->getRepository(Institution::class)->findOneBy(['name' => 'École avec logo']);
        self::assertNotNull($institution->getLogo());
        self::assertStringStartsWith('uploads/institutions/', $institution->getLogo());

        $logoPath = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$institution->getLogo();
        self::assertFileExists($logoPath);
        @unlink($logoPath);
    }

    public function testInstitutionLogoUploadRejectsNonImageFile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $admin = $this->makeUser($em, 'admin.badlogo@example.com', ['ROLE_ADMIN'], 'admin-badlogo');
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/etablissements');
        $token = $crawler->filter('form[action="/admin/etablissements/ajouter"] input[name="_csrf_token"]')->attr('value');

        $sourceFile = sys_get_temp_dir().'/moumtou-test-logo.txt';
        file_put_contents($sourceFile, 'not an image');
        $uploadedFile = new UploadedFile($sourceFile, 'logo.txt', 'text/plain', null, true);

        $client->request('POST', '/admin/etablissements/ajouter', [
            'name' => 'École avec mauvais logo',
            'type' => 'ecole',
            '_csrf_token' => $token,
        ], ['logo' => $uploadedFile]);
        self::assertResponseRedirects('/admin/etablissements');
        $client->followRedirect();
        self::assertSelectorTextContains('.m-avis', 'Formats acceptés');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Institution::class)->findOneBy(['name' => 'École avec mauvais logo']), 'Ni établissement ni logo ne doivent être créés si le fichier est invalide.');
    }
}
