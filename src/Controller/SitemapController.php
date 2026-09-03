<?php

namespace App\Controller;

use App\Entity\Defense;
use App\Entity\Institution;
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

        // Soutenances publiques (cahier §12) : une soutenance n'est
        // publique que si le projet qui la porte l'est aussi — mêmes
        // règles que {@see \App\Controller\PublicDefenseController::show()},
        // jamais dupliquées ici différemment.
        $defenses = $em->getRepository(Defense::class)->createQueryBuilder('d')
            ->join('d.project', 'p')->addSelect('p')
            ->andWhere('p.status IN (:statuses)')->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES)
            ->getQuery()->getResult();

        foreach ($defenses as $defense) {
            $urls[] = [
                'loc' => $urlGenerator->generate('app_defense_show', ['slug' => $defense->getProject()->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority' => '0.5',
            ];
        }

        // Établissements publics (cahier §12) : uniquement ceux actifs,
        // seule condition de visibilité publique déjà définie sur
        // {@see Institution} (réutilisée telle quelle, cf. FONCTIONNALITÉ 9).
        $institutions = $em->getRepository(Institution::class)->findBy(['active' => true]);
        foreach ($institutions as $institution) {
            $urls[] = [
                'loc' => $urlGenerator->generate('app_institution_show', ['slug' => $institution->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority' => '0.4',
            ];
        }

        $xml = $this->renderView('sitemap.xml.twig', ['urls' => $urls]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
