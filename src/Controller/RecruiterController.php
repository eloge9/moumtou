<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Technology;
use App\Entity\User;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cahier des charges §4.5 : la recherche de talents est une fonctionnalité
 * réservée aux recruteurs ayant créé un compte, pas au grand public.
 */
#[IsGranted('ROLE_RECRUITER')]
class RecruiterController extends AbstractController
{
    #[Route('/recruteur', name: 'app_recruiter_search')]
    public function search(Request $request, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $technologyIds = array_map('intval', $request->query->all('technologies'));
        $city = trim((string) $request->query->get('city', ''));

        // Un talent = un compte ayant publié au moins un projet visible publiquement.
        $publicOwnerIdsDql = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->select('IDENTITY(p.owner)')
            ->where('p.status IN (:statuses)')
            ->getDQL();

        $qb = $em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere($em->createQueryBuilder()->expr()->in('u.id', $publicOwnerIdsDql))
            ->setParameter('statuses', ProjectRepository::PUBLIC_STATUSES);

        if ($query) {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR u.bio LIKE :q')
                ->setParameter('q', '%'.$query.'%');
        }
        if ($city) {
            $qb->andWhere('u.city LIKE :city')->setParameter('city', '%'.$city.'%');
        }
        if ($technologyIds) {
            $qb->join('u.technologies', 'tech')->andWhere('tech.id IN (:techIds)')->setParameter('techIds', $technologyIds);
        }

        $talents = $qb->distinct()->getQuery()->getResult();

        // Compatibilité simple : proportion des technologies recherchées présentes chez le talent.
        $results = array_map(function (User $talent) use ($technologyIds) {
            $talentTechIds = array_map(fn ($t) => $t->getId(), $talent->getTechnologies()->toArray());
            $compatibility = $technologyIds
                ? (int) round(100 * \count(array_intersect($technologyIds, $talentTechIds)) / \count($technologyIds))
                : null;

            return ['user' => $talent, 'compatibility' => $compatibility];
        }, $talents);

        usort($results, fn ($a, $b) => ($b['compatibility'] ?? 0) <=> ($a['compatibility'] ?? 0));

        return $this->render('recruiter/search.html.twig', [
            'active_nav' => 'talents',
            'results' => $results,
            'query' => $query,
            'city' => $city,
            'technologyIds' => $technologyIds,
            'technologies' => $em->getRepository(Technology::class)->findBy([], ['name' => 'ASC']),
        ]);
    }
}
