<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Skill;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Enum\Availability;
use App\Enum\ProjectType;
use App\Repository\UserRepository;
use App\Search\TalentSearchCriteria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cahier des charges §4.5 et §32/§33 : la recherche de talents est une
 * fonctionnalité réservée aux recruteurs ayant créé un compte, pas au grand
 * public (le grand public dispose de la recherche générale sur /recherche).
 */
#[IsGranted('ROLE_RECRUITER')]
class RecruiterController extends AbstractController
{
    #[Route('/recruteur', name: 'app_recruiter_search')]
    public function search(Request $request, EntityManagerInterface $em, UserRepository $userRepository): Response
    {
        $criteria = $this->buildCriteria($request);
        $result = $userRepository->search($criteria);

        // Compatibilité simple : proportion des technologies recherchées présentes chez le talent.
        $results = array_map(function ($talent) use ($criteria) {
            $talentTechIds = array_map(fn ($t) => $t->getId(), $talent->getTechnologies()->toArray());
            $compatibility = $criteria->technologyIds
                ? (int) round(100 * \count(array_intersect($criteria->technologyIds, $talentTechIds)) / \count($criteria->technologyIds))
                : null;

            return ['user' => $talent, 'compatibility' => $compatibility];
        }, $result['items']);

        return $this->render('recruiter/search.html.twig', [
            'active_nav' => 'talents',
            'results' => $results,
            'total' => $result['total'],
            'criteria' => $criteria,
            'pageCount' => (int) ceil($result['total'] / $criteria->perPage),
            'technologies' => $em->getRepository(Technology::class)->findBy([], ['name' => 'ASC']),
            'skills' => $em->getRepository(Skill::class)->findBy([], ['name' => 'ASC']),
            'institutions' => $em->getRepository(Institution::class)->createQueryBuilder('i')
                ->andWhere('i.active = true')->orderBy('i.name', 'ASC')->getQuery()->getResult(),
            'domains' => $em->getRepository(Domain::class)->findBy([], ['name' => 'ASC']),
            'mentions' => $em->getRepository(Mention::class)->findBy([], ['name' => 'ASC']),
            'specialties' => $em->getRepository(Specialty::class)->findBy([], ['name' => 'ASC']),
            'projectTypes' => ProjectType::cases(),
            'availabilities' => Availability::cases(),
        ]);
    }

    private function buildCriteria(Request $request): TalentSearchCriteria
    {
        $techMode = TalentSearchCriteria::TECH_MODE_ALL === $request->query->get('tech_mode') ? TalentSearchCriteria::TECH_MODE_ALL : TalentSearchCriteria::TECH_MODE_ANY;
        $projectTypes = array_values(array_filter($request->query->all('project_type'), fn ($v) => null !== ProjectType::tryFrom($v)));
        $availability = Availability::tryFrom((string) $request->query->get('availability'));
        $sort = \in_array($request->query->get('sort'), [TalentSearchCriteria::SORT_RECENT, TalentSearchCriteria::SORT_NAME], true)
            ? $request->query->get('sort')
            : TalentSearchCriteria::SORT_RELEVANCE;

        return new TalentSearchCriteria(
            query: $request->query->get('q') ?: null,
            technologyIds: array_slice(array_map('intval', $request->query->all('technologies')), 0, 20),
            techMode: $techMode,
            skillIds: array_slice(array_map('intval', $request->query->all('skills')), 0, 20),
            country: $request->query->get('country') ?: null,
            city: $request->query->get('city') ?: null,
            institutionId: $this->optionalInt($request, 'institution'),
            domainId: $this->optionalInt($request, 'domain'),
            mentionId: $this->optionalInt($request, 'mention'),
            specialtyId: $this->optionalInt($request, 'specialty'),
            projectTypes: $projectTypes,
            yearMin: $this->optionalInt($request, 'year_min'),
            availability: $availability?->value,
            sort: $sort,
            page: max(1, $this->optionalInt($request, 'page') ?? 1),
        );
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);

        return (null === $raw || '' === $raw) ? null : (int) $raw;
    }
}
