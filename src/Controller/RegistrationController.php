<?php

namespace App\Controller;

use App\Entity\JuryMember;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\UserStatus;
use App\Form\RegistrationFormType;
use App\Security\EmailVerifier;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RegistrationController extends AbstractController
{
    /**
     * Routes de complétion selon le rôle choisi à l'inscription
     * (inscription/rôles multiples §3/§9) : TALENT seul mène directement à
     * la complétion du profil de base, les autres choix mènent d'abord au
     * formulaire dédié à ce rôle — le rôle additionnel n'est accordé qu'une
     * fois ce formulaire validé (§12), jamais dès l'inscription.
     */
    private const NEXT_ROUTE_BY_ACCOUNT_TYPE = [
        'student' => 'app_become_student',
        'teacher' => 'app_become_teacher',
        'recruiter' => 'app_recruiter_profile_edit',
    ];

    #[Route('/inscription', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        EmailVerifier $emailVerifier,
        SlugGenerator $slugGenerator,
        RateLimiterFactory $registrationLimiter,
        Security $security,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST') && !$registrationLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            $this->addFlash('erreur', 'Trop de tentatives d\'inscription depuis cette adresse. Merci de réessayer dans quelques instants.');

            return $this->redirectToRoute('app_register');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var AccountType $accountType */
            $accountType = $form->get('accountType')->getData();

            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            // TALENT est toujours le rôle de base (règle 1/5) ; le rôle
            // additionnel choisi (étudiant/enseignant/recruteur) n'est
            // ajouté qu'une fois son formulaire dédié complété (règle 6/7),
            // jamais ici.
            $user->setRoles(['ROLE_TALENT']);
            $user->setStatus(UserStatus::ACTIF);
            $user->setSlug($slugGenerator->generateUnique($user->getFullName(), User::class));

            $entityManager->persist($user);
            $entityManager->flush();

            // Un enseignant peut avoir été cité comme membre du jury avant
            // même de créer son compte (cahier des charges §15) : on relie
            // rétroactivement ces invitations à son nouveau compte, dès
            // l'inscription (avant même que le rôle enseignant ne soit
            // formellement activé) pour ne perdre aucune invitation.
            if (AccountType::TEACHER === $accountType) {
                $this->linkPendingJuryInvitations($user, $entityManager);
            }

            // L'e-mail de confirmation reste envoyé (preuve de propriété de
            // l'adresse), mais ne bloque plus la connexion : l'utilisateur
            // est authentifié immédiatement (règle 2/3), ce qu'aucun
            // UserChecker existant ne conditionne à `emailVerified`.
            $emailVerifier->sendVerificationEmail($user);
            $security->login($user, 'form_login', 'main');

            return $this->redirectToRoute('app_welcome', AccountType::TALENT === $accountType ? [] : ['role' => $accountType->value]);
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    /**
     * « Bienvenue sur MOUMTOU » (§5) : première page vue après l'inscription
     * (et la connexion automatique), avec un unique appel à l'action vers la
     * complétion adaptée au choix fait à l'inscription.
     */
    #[Route('/bienvenue', name: 'app_welcome')]
    #[IsGranted('ROLE_USER')]
    public function welcome(Request $request): Response
    {
        $role = (string) $request->query->get('role', '');
        $nextRoute = self::NEXT_ROUTE_BY_ACCOUNT_TYPE[$role] ?? 'app_profile_edit';

        return $this->render('security/welcome.html.twig', [
            'nextRoute' => $nextRoute,
        ]);
    }

    #[Route('/verifier-email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, EmailVerifier $emailVerifier, EntityManagerInterface $entityManager): Response
    {
        $id = (int) $request->query->get('id');
        $expires = (int) $request->query->get('expires');

        $user = $entityManager->getRepository(User::class)->find($id);

        if (!$user || !$emailVerifier->isSignedUrlValid($request->getUri(), $expires)) {
            $this->addFlash('erreur', 'Ce lien de confirmation est invalide ou a expiré.');

            return $this->redirectToRoute('app_register');
        }

        if (!$user->isEmailVerified()) {
            $user->setEmailVerified(true);
            $entityManager->flush();
        }

        $this->addFlash('succes', 'Votre adresse e-mail est confirmée. Vous pouvez vous connecter.');

        return $this->redirectToRoute('app_login');
    }

    private function linkPendingJuryInvitations(User $teacher, EntityManagerInterface $em): void
    {
        $invitations = $em->getRepository(JuryMember::class)->findBy(['email' => $teacher->getEmail(), 'invitedUser' => null]);
        foreach ($invitations as $invitation) {
            $invitation->setInvitedUser($teacher);
        }
        if ($invitations) {
            $em->flush();
        }
    }
}
