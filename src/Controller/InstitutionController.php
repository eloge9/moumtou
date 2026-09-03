<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Enum\DefenseStatus;
use App\Enum\ProjectType;
use App\Repository\InstitutionRepository;
use App\Repository\ProjectPhotoRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Search\InstitutionSearchCriteria;
use App\Search\ProjectSearchCriteria;
use App\Search\TalentSearchCriteria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Espace public des établissements : annuaire + page détail. Nouvelle porte
 * d'entrée vers les données existantes (projets, soutenances, talents) —
 * réutilise entièrement les moteurs de recherche de la FONCTIONNALITÉ 6
 * (ProjectRepository::search / UserRepository::search) plutôt que d'en
 * recréer un troisième.
 */
class InstitutionController extends AbstractController
{
    private const TABS = ['apercu', 'projets', 'soutenances', 'talents'];

    #[Route('/etablissements', name: 'app_institution_index', methods: ['GET'])]
    public function index(Request $request, InstitutionRepository $institutionRepository): Response
    {
        $criteria = new InstitutionSearchCriteria(
            query: $request->query->get('q') ?: null,
            country: $request->query->get('country') ?: null,
            city: $request->query->get('city') ?: null,
            page: max(1, (int) ($request->query->get('page') ?: 1)),
        );

        $result = $institutionRepository->search($criteria);
        $institutionIds = array_map(fn (Institution $i) => $i->getId(), $result['items']);
        $counts = $institutionRepository->countProjectsAndDefensesByInstitutions($institutionIds);

        return $this->render('institution/index.html.twig', [
            'active_nav' => 'institutions',
            'institutions' => $result['items'],
            'total' => $result['total'],
            'criteria' => $criteria,
            'counts' => $counts,
            'countries' => $institutionRepository->distinctCountries(),
            'pageCount' => (int) ceil($result['total'] / $criteria->perPage),
        ]);
    }

    #[Route('/etablissements/{slug}', name: 'app_institution_show', methods: ['GET'])]
    public function show(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        InstitutionRepository $institutionRepository,
        ProjectRepository $projectRepository,
        ProjectPhotoRepository $projectPhotoRepository,
        UserRepository $userRepository,
        UrlGeneratorInterface $urlGenerator,
        \App\Service\ReferenceDataProvider $referenceData,
    ): Response {
        $institution = $em->getRepository(Institution::class)->findOneBy(['slug' => $slug, 'active' => true]);
        if (!$institution) {
            throw $this->createNotFoundException('Établissement introuvable.');
        }

        $tab = \in_array($request->query->get('tab'), self::TABS, true) ? $request->query->get('tab') : 'apercu';
        $stats = $institutionRepository->computeStats($institution);
        $publicUrl = $urlGenerator->generate('app_institution_show', ['slug' => $institution->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);

        $data = [
            'active_nav' => 'institutions',
            'institution' => $institution,
            'stats' => $stats,
            'tab' => $tab,
            'publicUrl' => $publicUrl,
        ];

        if ('apercu' === $tab) {
            $data['recentProjects'] = $projectRepository->search(new ProjectSearchCriteria(institutionId: $institution->getId(), perPage: 6))['items'];
            $data['coverPhotos'] = $projectPhotoRepository->findCoversForProjects($data['recentProjects']);
            $data['upcomingDefenses'] = $projectRepository->search(new ProjectSearchCriteria(
                institutionId: $institution->getId(),
                types: [ProjectType::SOUTENANCE->value],
                defenseStatuses: [DefenseStatus::ANNONCEE->value],
                sort: ProjectSearchCriteria::SORT_OLDEST,
                perPage: 4,
            ))['items'];
        } elseif ('projets' === $tab) {
            $criteria = $this->buildProjectCriteria($request, $institution->getId());
            $result = $projectRepository->search($criteria);
            $data['projectCriteria'] = $criteria;
            $data['projects'] = $result['items'];
            $data['coverPhotos'] = $projectPhotoRepository->findCoversForProjects($result['items']);
            $data['projectsTotal'] = $result['total'];
            $data['projectsPageCount'] = (int) ceil($result['total'] / $criteria->perPage);
            $data['domains'] = $referenceData->domains();
            $data['mentions'] = $referenceData->mentions();
            $data['specialties'] = $referenceData->specialties();
            $data['technologies'] = $referenceData->technologies();
            $data['projectTypes'] = ProjectType::cases();
        } elseif ('soutenances' === $tab) {
            $criteria = $this->buildDefenseCriteria($request, $institution->getId());
            $result = $projectRepository->search($criteria);
            $data['defenseCriteria'] = $criteria;
            $data['periode'] = $request->query->get('periode', '');
            $data['defenses'] = $result['items'];
            $data['defensesTotal'] = $result['total'];
            $data['defensesPageCount'] = (int) ceil($result['total'] / $criteria->perPage);
            $data['domains'] = $referenceData->domains();
            $data['mentions'] = $referenceData->mentions();
            $data['specialties'] = $referenceData->specialties();
        } elseif ('talents' === $tab) {
            $talentCriteria = new TalentSearchCriteria(
                institutionId: $institution->getId(),
                page: max(1, (int) ($request->query->get('page') ?: 1)),
            );
            $result = $userRepository->search($talentCriteria);
            $data['talents'] = $result['items'];
            $data['talentsTotal'] = $result['total'];
            $data['talentsPageCount'] = (int) ceil($result['total'] / $talentCriteria->perPage);
            $data['talentCriteria'] = $talentCriteria;
        }

        return $this->render('institution/show.html.twig', $data);
    }

    /**
     * Filtres de l'onglet « Projets » (cahier §25) : domaine, mention,
     * spécialité, technologie, année, type, vérifié — combinables,
     * toujours restreints à cet établissement.
     */
    private function buildProjectCriteria(Request $request, int $institutionId): ProjectSearchCriteria
    {
        $types = array_values(array_filter($request->query->all('types'), fn ($v) => null !== ProjectType::tryFrom($v)));

        return new ProjectSearchCriteria(
            query: $request->query->get('q') ?: null,
            types: $types,
            domainId: $this->optionalInt($request, 'domain'),
            mentionId: $this->optionalInt($request, 'mention'),
            specialtyId: $this->optionalInt($request, 'specialty'),
            technologyIds: array_slice(array_map('intval', $request->query->all('technologies')), 0, 20),
            institutionId: $institutionId,
            yearMin: $this->optionalInt($request, 'year_min'),
            statuses: $request->query->getBoolean('verified') ? ['verifie'] : [],
            sort: ProjectSearchCriteria::SORT_RECENT,
            page: max(1, $this->optionalInt($request, 'page') ?? 1),
        );
    }

    /**
     * Filtres de l'onglet « Soutenances » (cahier §26) : à venir/réalisées,
     * vérifiées, année, domaine, mention, spécialité — toujours restreints
     * au type "soutenance" et à cet établissement.
     */
    private function buildDefenseCriteria(Request $request, int $institutionId): ProjectSearchCriteria
    {
        $defenseStatuses = match ($request->query->get('periode')) {
            'a_venir' => [DefenseStatus::ANNONCEE->value],
            'realisees' => [DefenseStatus::REALISEE->value, DefenseStatus::VERIFIEE->value],
            default => [],
        };

        return new ProjectSearchCriteria(
            types: [ProjectType::SOUTENANCE->value],
            domainId: $this->optionalInt($request, 'domain'),
            mentionId: $this->optionalInt($request, 'mention'),
            specialtyId: $this->optionalInt($request, 'specialty'),
            institutionId: $institutionId,
            yearMin: $this->optionalInt($request, 'year_min'),
            defenseVerified: $request->query->getBoolean('defense_verified'),
            defenseStatuses: $defenseStatuses,
            sort: ProjectSearchCriteria::SORT_RECENT,
            page: max(1, $this->optionalInt($request, 'page') ?? 1),
        );
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);

        return (null === $raw || '' === $raw) ? null : (int) $raw;
    }
}
