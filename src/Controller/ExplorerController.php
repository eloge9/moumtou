<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
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
    public function index(Request $request, ProjectRepository $projectRepository, EntityManagerInterface $em): Response
    {
        $criteria = $this->buildCriteria($request);
        $result = $projectRepository->search($criteria);

        $countByType = $projectRepository->countByType();

        return $this->render('explorer/index.html.twig', [
            'active_nav' => 'explorer',
            'projects' => $result['items'],
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

        return new ProjectSearchCriteria(
            types: $types,
            domainId: $request->query->getInt('domain') ?: null,
            mentionId: $request->query->getInt('mention') ?: null,
            specialtyId: $request->query->getInt('specialty') ?: null,
            technologyIds: array_map('intval', $request->query->all('technologies')),
            statuses: $statuses,
            institutionId: $request->query->getInt('institution') ?: null,
            country: $request->query->get('country') ?: null,
            city: $request->query->get('city') ?: null,
            yearMin: $request->query->getInt('year_min') ?: null,
            sort: \in_array($request->query->get('sort'), [ProjectSearchCriteria::SORT_RATING, ProjectSearchCriteria::SORT_VIEWS], true)
                ? $request->query->get('sort')
                : ProjectSearchCriteria::SORT_RECENT,
            page: max(1, $request->query->getInt('page', 1)),
        );
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
