<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Form\RegistrationFormType;
use App\Security\EmailVerifier;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->setRoles(['ROLE_USER']);
            $user->setStatus(UserStatus::ACTIF);
            $user->setSlug($slugGenerator->generateUnique($user->getFullName(), User::class));

            $entityManager->persist($user);
            $entityManager->flush();

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
        $id = $request->query->getInt('id');
        $expires = $request->query->getInt('expires');

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
}
