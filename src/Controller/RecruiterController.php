<?php

namespace App\Controller;

use App\Entity\ContactRequest;
use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\RecruiterFavorite;
use App\Entity\Skill;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\Availability;
use App\Enum\ContactRequestStatus;
use App\Enum\NotificationType;
use App\Enum\ProjectType;
use App\Repository\AnalyticsEventRepository;
use App\Repository\ContactRequestRepository;
use App\Repository\RecruiterFavoriteRepository;
use App\Repository\TalentViewRepository;
use App\Repository\UserRepository;
use App\Search\TalentSearchCriteria;
use App\Security\ContactRequestMailer;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cahier des charges §4.5, §32/§33 et FONCTIONNALITÉ 7 : espace recruteur —
 * recherche de talents (réutilise la FONCTIONNALITÉ 6), favoris, demandes de
 * contact et tableau de bord. Le parcours "devenir recruteur" (ajout du rôle
 * à un compte existant) vit dans {@see AccountController}, seule action de
 * ce périmètre accessible sans avoir déjà ROLE_RECRUITER.
 */
#[IsGranted('ROLE_RECRUITER')]
class RecruiterController extends AbstractController
{
    private const MAX_MESSAGE_LENGTH = 1000;

    #[Route('/recruteur', name: 'app_recruiter_search')]
    public function search(Request $request, EntityManagerInterface $em, UserRepository $userRepository, RecruiterFavoriteRepository $favoriteRepository, ContactRequestRepository $contactRequestRepository, \App\Service\ReferenceDataProvider $referenceData): Response
    {
        $criteria = $this->buildCriteria($request);
        $result = $userRepository->search($criteria);

        $talentIds = array_map(fn (User $t) => $t->getId(), $result['items']);
        $projectCounts = $userRepository->countProjectsByTalents($talentIds);
        $favoriteIds = $favoriteRepository->favoriteTalentIds($this->getUser());

        // Compatibilité simple : proportion des technologies recherchées présentes chez le talent.
        $results = array_map(function (User $talent) use ($criteria, $projectCounts, $favoriteIds, $contactRequestRepository) {
            $talentTechIds = array_map(fn ($t) => $t->getId(), $talent->getTechnologies()->toArray());
            $compatibility = $criteria->technologyIds
                ? (int) round(100 * \count(array_intersect($criteria->technologyIds, $talentTechIds)) / \count($criteria->technologyIds))
                : null;

            return [
                'user' => $talent,
                'compatibility' => $compatibility,
                'counts' => $projectCounts[$talent->getId()] ?? ['total' => 0, 'verified' => 0, 'verifiedDefenses' => 0],
                'isFavorite' => \in_array($talent->getId(), $favoriteIds, true),
                'hasPendingRequest' => $contactRequestRepository->hasPendingRequest($this->getUser(), $talent),
            ];
        }, $result['items']);

        return $this->render('recruiter/search.html.twig', [
            'active_nav' => 'talents',
            'results' => $results,
            'total' => $result['total'],
            'criteria' => $criteria,
            'pageCount' => (int) ceil($result['total'] / $criteria->perPage),
            'technologies' => $referenceData->technologies(),
            'skills' => $em->getRepository(Skill::class)->findBy([], ['name' => 'ASC']),
            'institutions' => $em->getRepository(Institution::class)->createQueryBuilder('i')
                ->andWhere('i.active = true')->orderBy('i.name', 'ASC')->getQuery()->getResult(),
            'domains' => $referenceData->domains(),
            'mentions' => $referenceData->mentions(),
            'specialties' => $referenceData->specialties(),
            'projectTypes' => ProjectType::cases(),
            'availabilities' => Availability::cases(),
        ]);
    }

    #[Route('/recruteur/tableau-de-bord', name: 'app_recruiter_dashboard')]
    public function dashboard(
        EntityManagerInterface $em,
        TalentViewRepository $talentViewRepository,
        RecruiterFavoriteRepository $favoriteRepository,
        ContactRequestRepository $contactRequestRepository,
        AnalyticsEventRepository $analyticsEventRepository,
    ): Response {
        /** @var User $recruiter */
        $recruiter = $this->getUser();

        $sentRequests = $em->getRepository(ContactRequest::class)->findBy(['recruiter' => $recruiter], ['createdAt' => 'DESC'], 5);
        $recentFavorites = $em->getRepository(RecruiterFavorite::class)->findBy(['recruiter' => $recruiter], ['createdAt' => 'DESC'], 5);

        return $this->render('recruiter/dashboard.html.twig', [
            'active_nav' => 'talents',
            'recruiter' => $recruiter,
            'stats' => [
                'talentsViewedCount' => $talentViewRepository->countDistinctTalents($recruiter),
                // Cahier des charges — FONCTIONNALITÉ 12 §18 : "Projets
                // consultés", à partir des mêmes événements de vue que le
                // reste de l'application, pas d'un journal séparé.
                'projectsViewedCount' => $analyticsEventRepository->distinctProjectsViewedByUser($recruiter),
                'favoritesCount' => (int) $em->getRepository(RecruiterFavorite::class)->count(['recruiter' => $recruiter]),
                'sentCount' => (int) $em->getRepository(ContactRequest::class)->count(['recruiter' => $recruiter]),
                'acceptedCount' => (int) $em->getRepository(ContactRequest::class)->count(['recruiter' => $recruiter, 'status' => ContactRequestStatus::ACCEPTED]),
                'pendingCount' => (int) $em->getRepository(ContactRequest::class)->count(['recruiter' => $recruiter, 'status' => ContactRequestStatus::PENDING]),
            ],
            'recentViews' => $talentViewRepository->findRecentDistinct($recruiter, 5),
            'recentFavorites' => $recentFavorites,
            'sentRequests' => $sentRequests,
        ]);
    }

    #[Route('/recruteur/favoris', name: 'app_recruiter_favorites')]
    public function favorites(EntityManagerInterface $em, UserRepository $userRepository): Response
    {
        /** @var User $recruiter */
        $recruiter = $this->getUser();

        $favorites = $em->getRepository(RecruiterFavorite::class)->createQueryBuilder('f')
            ->join('f.talent', 't')->addSelect('t')
            // Association OneToOne côté inverse : sans cette jointure,
            // Doctrine déclenche une requête par talent favori (cahier —
            // FONCTIONNALITÉ 17 §4/§19).
            ->leftJoin('t.recruiterProfile', 'talentRecruiterProfile')->addSelect('talentRecruiterProfile')
            ->andWhere('f.recruiter = :recruiter')->setParameter('recruiter', $recruiter)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()->getResult();

        $talentIds = array_map(fn (RecruiterFavorite $f) => $f->getTalent()->getId(), $favorites);
        $projectCounts = $userRepository->countProjectsByTalents($talentIds);

        return $this->render('recruiter/favorites.html.twig', [
            'active_nav' => 'talents',
            'favorites' => $favorites,
            'projectCounts' => $projectCounts,
        ]);
    }

    #[Route('/recruteur/favoris/{talentId}/ajouter', name: 'app_recruiter_favorite_add', methods: ['POST'])]
    public function addFavorite(int $talentId, Request $request, EntityManagerInterface $em, RecruiterFavoriteRepository $favoriteRepository): Response
    {
        $talent = $em->getRepository(User::class)->find($talentId);
        if (!$talent || !\in_array('ROLE_TALENT', $talent->getRoles(), true)) {
            throw $this->createNotFoundException();
        }

        $this->assertCsrf($request, 'favori-'.$talentId);

        /** @var User $recruiter */
        $recruiter = $this->getUser();

        if (!$favoriteRepository->isFavorite($recruiter, $talent)) {
            $favorite = new RecruiterFavorite();
            $favorite->setRecruiter($recruiter);
            $favorite->setTalent($talent);
            $em->persist($favorite);
            $em->flush();
        }

        $this->addFlash('succes', 'Talent ajouté à vos favoris.');

        return $this->redirectToRoute('app_profile_show', ['slug' => $talent->getSlug()]);
    }

    #[Route('/recruteur/favoris/{talentId}/retirer', name: 'app_recruiter_favorite_remove', methods: ['POST'])]
    public function removeFavorite(int $talentId, Request $request, EntityManagerInterface $em): Response
    {
        $talent = $em->getRepository(User::class)->find($talentId);
        if (!$talent) {
            throw $this->createNotFoundException();
        }

        $this->assertCsrf($request, 'favori-retirer-'.$talentId);

        $favorite = $em->getRepository(RecruiterFavorite::class)->findOneBy(['recruiter' => $this->getUser(), 'talent' => $talent]);
        if ($favorite) {
            $em->remove($favorite);
            $em->flush();
        }

        $this->addFlash('succes', 'Talent retiré de vos favoris.');

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_recruiter_favorites'));
    }

    #[Route('/recruteur/demandes', name: 'app_recruiter_contact_requests')]
    public function contactRequests(EntityManagerInterface $em): Response
    {
        $requests = $em->getRepository(ContactRequest::class)->createQueryBuilder('c')
            ->join('c.talent', 't')->addSelect('t')
            ->andWhere('c.recruiter = :recruiter')->setParameter('recruiter', $this->getUser())
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()->getResult();

        return $this->render('recruiter/contact_requests.html.twig', [
            'active_nav' => 'talents',
            'requests' => $requests,
        ]);
    }

    #[Route('/recruteur/demandes/{talentId}/envoyer', name: 'app_recruiter_contact_request_send', methods: ['POST'])]
    public function sendContactRequest(
        int $talentId,
        Request $request,
        EntityManagerInterface $em,
        ContactRequestRepository $contactRequestRepository,
        ContactRequestMailer $mailer,
        RateLimiterFactory $contactRequestLimiter,
        NotificationService $notificationService,
    ): Response {
        $talent = $em->getRepository(User::class)->find($talentId);
        if (!$talent || !\in_array('ROLE_TALENT', $talent->getRoles(), true)) {
            throw $this->createNotFoundException();
        }

        /** @var User $recruiter */
        $recruiter = $this->getUser();

        // Vérifié avant le jeton CSRF : la page ne propose jamais ce
        // formulaire pour soi-même, donc une requête qui cible son propre
        // compte est nécessairement anormale — inutile d'exiger un jeton
        // valide pour la rejeter.
        if ($recruiter === $talent) {
            $this->addFlash('erreur', 'Vous ne pouvez pas vous contacter vous-même.');

            return $this->redirectToRoute('app_profile_show', ['slug' => $talent->getSlug()]);
        }

        $this->assertCsrf($request, 'contacter-'.$talentId);

        if ($contactRequestRepository->hasPendingRequest($recruiter, $talent)) {
            $this->addFlash('erreur', 'Vous avez déjà une demande en attente auprès de ce talent.');

            return $this->redirectToRoute('app_profile_show', ['slug' => $talent->getSlug()]);
        }

        if (!$contactRequestLimiter->create('recruiter-'.$recruiter->getId())->consume(1)->isAccepted()) {
            $this->addFlash('erreur', 'Vous avez envoyé trop de demandes de contact récemment. Merci de patienter.');

            return $this->redirectToRoute('app_profile_show', ['slug' => $talent->getSlug()]);
        }

        $message = trim((string) $request->request->get('message'));
        if ('' === $message) {
            $this->addFlash('erreur', 'Le message ne peut pas être vide.');

            return $this->redirectToRoute('app_profile_show', ['slug' => $talent->getSlug()]);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $this->addFlash('erreur', \sprintf('Le message est trop long (%d caractères maximum).', self::MAX_MESSAGE_LENGTH));

            return $this->redirectToRoute('app_profile_show', ['slug' => $talent->getSlug()]);
        }

        $contactRequest = new ContactRequest();
        $contactRequest->setRecruiter($recruiter);
        $contactRequest->setTalent($talent);
        $contactRequest->setMessage($message);
        $em->persist($contactRequest);
        $em->flush();

        $mailer->notifyTalentOfNewRequest($contactRequest);
        // sendEmail: false — le mailer dédié ci-dessus vient déjà d'envoyer
        // l'e-mail, la notification interne ne doit pas en envoyer un second.
        $notificationService->notify(
            $talent,
            NotificationType::CONTACT_REQUEST_RECEIVED,
            'Nouvelle demande de contact',
            \sprintf('%s souhaite entrer en contact avec vous.', $recruiter->getFullName()),
            $this->generateUrl('app_talent_contact_requests'),
            sendEmail: false,
        );

        $this->addFlash('succes', 'Votre demande de contact a été envoyée.');

        return $this->redirectToRoute('app_profile_show', ['slug' => $talent->getSlug()]);
    }

    #[Route('/recruteur/demandes/{id}/annuler', name: 'app_recruiter_contact_request_cancel', methods: ['POST'])]
    public function cancelContactRequest(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $contactRequest = $em->getRepository(ContactRequest::class)->find($id);
        if (!$contactRequest || $contactRequest->getRecruiter() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $this->assertCsrf($request, 'annuler-demande-'.$id);

        if (ContactRequestStatus::PENDING === $contactRequest->getStatus()) {
            $contactRequest->setStatus(ContactRequestStatus::CANCELLED);
            $contactRequest->setRespondedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('succes', 'La demande a été annulée.');
        }

        return $this->redirectToRoute('app_recruiter_contact_requests');
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }
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
