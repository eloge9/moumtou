<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §35/§38 : pages publiques indexables (meta, Open
 * Graph, sitemap).
 */
class SeoTest extends FunctionalTestCase
{
    public function testSitemapListsPublicProjectAndProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new User())->setEmail('seo@example.com')->setFirstName('Seo')->setLastName('Test')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('seo-test');
        $owner->setPassword($hasher->hashPassword($owner, 'MotDePasse123'));
        $em->persist($owner);

        $project = new Project();
        $project->setName('Projet indexable');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-indexable');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/xml', $client->getResponse()->headers->get('Content-Type'));

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('/projets/projet-indexable', $content);
        self::assertStringContainsString('/profils/seo-test', $content);
    }

    public function testProjectPageHasOpenGraphAndStructuredData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new User())->setEmail('seo2@example.com')->setFirstName('Seo')->setLastName('Deux')
            ->setPhone('+22890000001')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('seo-deux');
        $owner->setPassword($hasher->hashPassword($owner, 'MotDePasse123'));
        $em->persist($owner);

        $project = new Project();
        $project->setName('Plateforme de gestion');
        $project->setShortDescription('Une plateforme de test pour le SEO.');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('plateforme-gestion-seo');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $crawler = $client->request('GET', '/projets/plateforme-gestion-seo');
        self::assertResponseIsSuccessful();

        self::assertSame(1, $crawler->filter('meta[property="og:title"]')->count());
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertStringContainsString('schema.org', $client->getResponse()->getContent());
    }
}
