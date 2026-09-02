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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        EmailVerifier $emailVerifier,
        SlugGenerator $slugGenerator,
        RateLimiterFactory $registrationLimiter,
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
            $user->setRoles([$accountType->role()]);
            $user->setStatus(UserStatus::ACTIF);
            $user->setSlug($slugGenerator->generateUnique($user->getFullName(), User::class));

            $entityManager->persist($user);
            $entityManager->flush();

            // Un enseignant peut avoir été cité comme membre du jury avant
            // même de créer son compte (cahier des charges §15) : on relie
            // rétroactivement ces invitations à son nouveau compte.
            if (AccountType::TEACHER === $accountType) {
                $this->linkPendingJuryInvitations($user, $entityManager);
            }

            $signedUrl = $emailVerifier->sendVerificationEmail($user);

            return $this->render('security/check_email.html.twig', [
                'email' => $user->getEmail(),
                // Uniquement affiché en développement, pour tester le parcours sans boîte mail réelle.
                'devSignedUrl' => $this->getParameter('kernel.environment') === 'dev' ? $signedUrl : null,
            ]);
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
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
