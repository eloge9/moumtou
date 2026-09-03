<?php

namespace App\Controller;

use App\Entity\Institution;
use App\Entity\Project;
use App\Entity\Technology;
use App\Entity\User;
use App\Repository\DefenseRepository;
use App\Repository\ProjectPhotoRepository;
use App\Repository\ProjectRepository;
use App\Repository\TechnologyRepository;
use App\Repository\UserRepository;
use App\Search\ProjectSearchCriteria;
use App\Search\TalentSearchCriteria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Recherche générale MOUMTOU (cahier des charges — FONCTIONNALITÉ 6) :
 * point d'entrée public combinant talents, projets, soutenances,
 * technologies et institutions. Les vues spécialisées (/explorer pour les
 * projets, /recruteur pour l'outil recruteur enrichi) restent les moteurs
 * de référence — cette page les réutilise plutôt que de les dupliquer.
 */
class SearchController extends AbstractController
{
    private const PREVIEW_LIMIT = 6;
    private const SUGGESTION_LIMIT = 5;

    #[Route('/recherche', name: 'app_search')]
    public function index(
        Request $request,
        ProjectRepository $projectRepository,
        ProjectPhotoRepository $projectPhotoRepository,
        UserRepository $userRepository,
        TechnologyRepository $technologyRepository,
        DefenseRepository $defenseRepository,
        EntityManagerInterface $em,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $type = $request->query->get('type');
        $hasFilters = $this->hasAnyFilterParam($request);

        if ('talents' === $type) {
            return $this->talentsView($request, $userRepository, $em, $query);
        }

        if (!$query && !$hasFilters) {
            $recentProjects = $projectRepository->search(new ProjectSearchCriteria(perPage: self::PREVIEW_LIMIT))['items'];

            return $this->render('search/index.html.twig', [
                'active_nav' => 'recherche',
                'empty' => true,
                'query' => '',
                'popularTechnologies' => $technologyRepository->findMostUsed(10),
                'recentProjects' => $recentProjects,
                'coverPhotos' => $projectPhotoRepository->findCoversForProjects($recentProjects),
                'upcomingDefenses' => $defenseRepository->findUpcoming(self::PREVIEW_LIMIT),
            ]);
        }

        // Vue d'ensemble : compte + aperçu par catégorie (cahier §3).
        $projectResult = $projectRepository->search(new ProjectSearchCriteria(query: $query ?: null, perPage: self::PREVIEW_LIMIT));
        $defenseResult = $projectRepository->search(new ProjectSearchCriteria(query: $query ?: null, types: ['soutenance'], perPage: self::PREVIEW_LIMIT));
        $talentResult = $userRepository->search(new TalentSearchCriteria(query: $query ?: null, perPage: self::PREVIEW_LIMIT));

        $technologies = $query
            ? $em->getRepository(Technology::class)->createQueryBuilder('t')
                ->andWhere('t.name LIKE :q')->setParameter('q', '%'.$query.'%')
                ->orderBy('t.name', 'ASC')->setMaxResults(self::PREVIEW_LIMIT)->getQuery()->getResult()
            : [];
        $institutions = $query
            ? $em->getRepository(Institution::class)->createQueryBuilder('i')
                ->andWhere('i.name LIKE :q')->andWhere('i.active = true')->setParameter('q', '%'.$query.'%')
                ->orderBy('i.name', 'ASC')->setMaxResults(self::PREVIEW_LIMIT)->getQuery()->getResult()
            : [];

        return $this->render('search/index.html.twig', [
            'active_nav' => 'recherche',
            'empty' => false,
            'query' => $query,
            'talents' => $talentResult['items'],
            'talentsTotal' => $talentResult['total'],
            'projects' => $projectResult['items'],
            'projectsTotal' => $projectResult['total'],
            'coverPhotos' => $projectPhotoRepository->findCoversForProjects($projectResult['items']),
            'defenses' => $defenseResult['items'],
            'defensesTotal' => $defenseResult['total'],
            'technologies' => $technologies,
            'institutions' => $institutions,
        ]);
    }

    /**
     * Vue « talents » ouverte au public (profils publics uniquement, sans
     * les informations réservées au recruteur — WhatsApp, score de
     * compatibilité — cahier §18/§33). L'outil recruteur enrichi reste sur
     * /recruteur, réservé à ROLE_RECRUITER.
     */
    private function talentsView(Request $request, UserRepository $userRepository, EntityManagerInterface $em, string $query): Response
    {
        $technologyIds = array_slice(array_map('intval', $request->query->all('technologies')), 0, 20);
        $techMode = TalentSearchCriteria::TECH_MODE_ALL === $request->query->get('tech_mode') ? TalentSearchCriteria::TECH_MODE_ALL : TalentSearchCriteria::TECH_MODE_ANY;
        $page = max(1, (int) ($request->query->get('page') ?: 1));

        $criteria = new TalentSearchCriteria(
            query: $query ?: null,
            technologyIds: $technologyIds,
            techMode: $techMode,
            country: $request->query->get('country') ?: null,
            city: $request->query->get('city') ?: null,
            page: $page,
        );
        $result = $userRepository->search($criteria);

        return $this->render('search/talents.html.twig', [
            'active_nav' => 'recherche',
            'query' => $query,
            'criteria' => $criteria,
            'talents' => $result['items'],
            'total' => $result['total'],
            'pageCount' => (int) ceil($result['total'] / $criteria->perPage),
            'technologies' => $em->getRepository(Technology::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * Autocomplétion (cahier §23) : suggestions courtes, protégées par un
     * debounce côté client (voir moumtou.js) — jamais appelée à chaque
     * frappe sans protection côté serveur non plus (longueur minimale).
     */
    #[Route('/recherche/suggestions', name: 'app_search_suggestions')]
    public function suggestions(Request $request, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 2) {
            return new JsonResponse(['suggestions' => []]);
        }

        $suggestions = [];

        $technologies = $em->getRepository(Technology::class)->createQueryBuilder('t')
            ->andWhere('t.name LIKE :q')->setParameter('q', $query.'%')
            ->orderBy('t.name', 'ASC')->setMaxResults(self::SUGGESTION_LIMIT)->getQuery()->getResult();
        foreach ($technologies as $technology) {
            $suggestions[] = [
                'type' => 'technologie',
                'label' => $technology->getName(),
                'url' => $urlGenerator->generate('app_explorer', ['technologies' => [$technology->getId()]]),
            ];
        }

        $publicOwnerIdsDql = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->select('IDENTITY(p.owner)')->where('p.status IN (:statuses)')->getDQL();
        $talents = $em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere($em->createQueryBuilder()->expr()->in('u.id', $publicOwnerIdsDql))
            ->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q')
            ->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES)
            ->setParameter('q', $query.'%')
            ->setMaxResults(self::SUGGESTION_LIMIT)->getQuery()->getResult();
        foreach ($talents as $talent) {
            $suggestions[] = [
                'type' => 'talent',
                'label' => $talent->getFullName(),
                'url' => $urlGenerator->generate('app_profile_show', ['slug' => $talent->getSlug()]),
            ];
        }

        $projects = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->andWhere('p.status IN (:statuses)')->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES)
            ->andWhere('p.name LIKE :q')->setParameter('q', $query.'%')
            ->orderBy('p.name', 'ASC')->setMaxResults(self::SUGGESTION_LIMIT)->getQuery()->getResult();
        foreach ($projects as $project) {
            $suggestions[] = [
                'type' => 'projet',
                'label' => $project->getName(),
                'url' => $urlGenerator->generate('app_project_show', ['slug' => $project->getSlug()]),
            ];
        }

        return new JsonResponse(['suggestions' => \array_slice($suggestions, 0, 12)]);
    }

    private function hasAnyFilterParam(Request $request): bool
    {
        foreach (['country', 'city', 'institution', 'domain', 'mention', 'specialty', 'project_type', 'year_min', 'verified', 'defense_verified'] as $key) {
            if ('' !== (string) $request->query->get($key, '')) {
                return true;
            }
        }
        foreach (['technologies', 'skills'] as $key) {
            if ($request->query->all($key)) {
                return true;
            }
        }

        return false;
    }
}
