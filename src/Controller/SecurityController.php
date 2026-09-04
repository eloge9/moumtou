<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\PasswordResetMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Cette méthode ne doit jamais être appelée : la déconnexion est interceptée par le pare-feu Symfony.');
    }

    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password')]
    public function forgotPassword(Request $request, EntityManagerInterface $em, PasswordResetMailer $mailer): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mot-de-passe-oublie', $request->request->get('_csrf_token'))) {
                throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
            }

            $email = trim((string) $request->request->get('email'));
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            $devSignedUrl = null;

            if ($user) {
                $signedUrl = $mailer->sendResetEmail($user);
                $devSignedUrl = $this->getParameter('kernel.environment') === 'dev' ? $signedUrl : null;
            }

            // Toujours le même message, que le compte existe ou non, pour ne pas
            // révéler quelles adresses sont inscrites.
            return $this->render('security/forgot_password_sent.html.twig', ['devSignedUrl' => $devSignedUrl]);
        }

        return $this->render('security/forgot_password.html.twig', [
            'prefillEmail' => trim((string) $request->query->get('email')) ?: null,
        ]);
    }

    #[Route('/reinitialiser-mot-de-passe', name: 'app_reset_password')]
    public function resetPassword(
        Request $request,
        EntityManagerInterface $em,
        PasswordResetMailer $mailer,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
    ): Response {
        $id = (int) $request->query->get('id');
        $expires = (int) $request->query->get('expires');
        $pwv = (string) $request->query->get('pwv');
        $user = $em->getRepository(User::class)->find($id);

        if (!$user || !$mailer->isSignedUrlValid($request->getUri(), $expires, $user, $pwv)) {
            throw $this->createNotFoundException('Ce lien de réinitialisation est invalide, a déjà été utilisé ou a expiré.');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reinitialiser-mot-de-passe', $request->request->get('_csrf_token'))) {
                throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
            }

            $password = (string) $request->request->get('password');
            $confirm = (string) $request->request->get('confirm');

            $violations = $validator->validate($password, [new NotBlank(), new Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]);
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }
            if ($password !== $confirm) {
                $errors[] = 'Les deux mots de passe doivent être identiques.';
            }

            if (!$errors) {
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $em->flush();

                $this->addFlash('succes', 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'errors' => $errors,
            'queryString' => $request->getQueryString(),
        ]);
    }
}
