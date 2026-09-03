<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Repository\ProjectPhotoRepository;
use App\Repository\ProjectRepository;
use App\Search\ProjectSearchCriteria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ExplorerController extends AbstractController
{
    #[Route('/explorer', name: 'app_explorer')]
    public function index(Request $request, ProjectRepository $projectRepository, ProjectPhotoRepository $projectPhotoRepository, EntityManagerInterface $em): Response
    {
        $criteria = $this->buildCriteria($request);
        $result = $projectRepository->search($criteria);

        $countByType = $projectRepository->countByType();

        return $this->render('explorer/index.html.twig', [
            'active_nav' => 'explorer',
            'projects' => $result['items'],
            'coverPhotos' => $projectPhotoRepository->findCoversForProjects($result['items']),
            'total' => $result['total'],
            'criteria' => $criteria,
            'countByType' => $countByType,
            'projectTypes' => ProjectType::cases(),
            'domains' => $em->getRepository(Domain::class)->findBy([], ['name' => 'ASC']),
            'mentions' => $em->getRepository(Mention::class)->findBy([], ['name' => 'ASC']),
            'specialties' => $em->getRepository(Specialty::class)->findBy([], ['name' => 'ASC']),
            'institutions' => $em->getRepository(Institution::class)->findBy([], ['name' => 'ASC']),
            'technologies' => $em->getRepository(Technology::class)->findBy([], ['name' => 'ASC']),
            'countries' => $this->distinctInstitutionCountries($em),
            'pageCount' => (int) ceil($result['total'] / $criteria->perPage),
        ]);
    }

    private function buildCriteria(Request $request): ProjectSearchCriteria
    {
        $types = array_values(array_filter($request->query->all('types'), fn ($v) => ProjectType::tryFrom($v) !== null));
        $statuses = array_values(array_filter(
            array_map(fn ($v) => ProjectStatus::tryFrom($v), $request->query->all('statuses')),
        ));

        $techMode = ProjectSearchCriteria::TECH_MODE_ALL === $request->query->get('tech_mode') ? ProjectSearchCriteria::TECH_MODE_ALL : ProjectSearchCriteria::TECH_MODE_ANY;
        $allowedSorts = [ProjectSearchCriteria::SORT_RATING, ProjectSearchCriteria::SORT_VIEWS, ProjectSearchCriteria::SORT_OLDEST, ProjectSearchCriteria::SORT_RELEVANCE];

        return new ProjectSearchCriteria(
            query: $request->query->get('q') ?: null,
            types: $types,
            domainId: $this->optionalInt($request, 'domain'),
            mentionId: $this->optionalInt($request, 'mention'),
            specialtyId: $this->optionalInt($request, 'specialty'),
            technologyIds: array_slice(array_map('intval', $request->query->all('technologies')), 0, 20),
            techMode: $techMode,
            statuses: $statuses,
            institutionId: $this->optionalInt($request, 'institution'),
            country: $request->query->get('country') ?: null,
            city: $request->query->get('city') ?: null,
            yearMin: $this->optionalInt($request, 'year_min'),
            defenseVerified: $request->query->getBoolean('defense_verified'),
            sort: \in_array($request->query->get('sort'), $allowedSorts, true)
                ? $request->query->get('sort')
                : ProjectSearchCriteria::SORT_RECENT,
            page: max(1, $this->optionalInt($request, 'page') ?? 1),
        );
    }

    /**
     * Lit un paramètre GET entier optionnel sans planter si la valeur est
     * vide ou absente (ex. un <select> non renseigné soumet une chaîne vide,
     * et Request::getInt() lève désormais une BadRequestException dans ce cas).
     */
    private function optionalInt(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);

        return ($raw === null || $raw === '') ? null : (int) $raw;
    }

    /**
     * @return string[]
     */
    private function distinctInstitutionCountries(EntityManagerInterface $em): array
    {
        $rows = $em->getRepository(Institution::class)->createQueryBuilder('i')
            ->select('DISTINCT i.country')
            ->where('i.country IS NOT NULL')
            ->orderBy('i.country', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'country');
    }
}
