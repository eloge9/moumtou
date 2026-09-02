<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cahier des charges §35/§38 : les pages publiques (projets, profils)
 * doivent être indexables ; un sitemap facilite leur découverte par les
 * moteurs de recherche.
 */
class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_sitemap')]
    public function sitemap(EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator): Response
    {
        $urls = [
            ['loc' => $urlGenerator->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), 'priority' => '1.0'],
            ['loc' => $urlGenerator->generate('app_explorer', [], UrlGeneratorInterface::ABSOLUTE_URL), 'priority' => '0.8'],
        ];

        $projects = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->andWhere('p.status IN (:statuses)')->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES)
            ->getQuery()->getResult();

        foreach ($projects as $project) {
            $urls[] = [
                'loc' => $urlGenerator->generate('app_project_show', ['slug' => $project->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => ($project->getPublishedAt() ?? $project->getCreatedAt())->format('Y-m-d'),
                'priority' => '0.6',
            ];
        }

        $usersWithPublicProjects = $em->getRepository(User::class)->createQueryBuilder('u')
            ->join('u.projects', 'p')
            ->andWhere('p.status IN (:statuses)')->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES)
            ->distinct()
            ->getQuery()->getResult();

        foreach ($usersWithPublicProjects as $user) {
            $urls[] = [
                'loc' => $urlGenerator->generate('app_profile_show', ['slug' => $user->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority' => '0.5',
            ];
        }

        $xml = $this->renderView('sitemap.xml.twig', ['urls' => $urls]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
