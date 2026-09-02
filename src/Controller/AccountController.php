<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changement de mot de passe et suppression de compte pour un utilisateur
 * déjà connecté (cahier des charges §5.3), distinct du parcours "mot de
 * passe oublié" qui, lui, ne suppose pas d'être authentifié.
 */
#[IsGranted('ROLE_USER')]
class AccountController extends AbstractController
{
    #[Route('/mon-compte/mot-de-passe', name: 'app_account_change_password', methods: ['POST'])]
    public function changePassword(Request $request, UserPasswordHasherInterface $passwordHasher, ValidatorInterface $validator, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('changer-mot-de-passe', $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        /** @var User $user */
        $user = $this->getUser();

        $current = (string) $request->request->get('current_password');
        $new = (string) $request->request->get('new_password');
        $confirm = (string) $request->request->get('new_password_confirm');

        if (!$passwordHasher->isPasswordValid($user, $current)) {
            $this->addFlash('erreur', 'Le mot de passe actuel est incorrect.');

            return $this->redirectToRoute('app_profile_edit');
        }

        $violations = $validator->validate($new, [new NotBlank(), new Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]);
        if (\count($violations) > 0 || $new !== $confirm) {
            $this->addFlash('erreur', $new !== $confirm ? 'Les deux mots de passe ne correspondent pas.' : $violations[0]->getMessage());

            return $this->redirectToRoute('app_profile_edit');
        }

        $user->setPassword($passwordHasher->hashPassword($user, $new));
        $em->flush();

        $this->addFlash('succes', 'Votre mot de passe a été modifié.');

        return $this->redirectToRoute('app_profile_edit');
    }

    #[Route('/mon-compte/supprimer', name: 'app_account_delete', methods: ['POST'])]
    public function delete(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em, TokenStorageInterface $tokenStorage): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-compte', $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$passwordHasher->isPasswordValid($user, (string) $request->request->get('password'))) {
            $this->addFlash('erreur', 'Mot de passe incorrect : le compte n\'a pas été supprimé.');

            return $this->redirectToRoute('app_profile_edit');
        }

        // Anonymisation plutôt que suppression physique : les projets déjà
        // publiés (potentiellement commentés/notés par d'autres comptes)
        // restent intègres en base, mais toutes les données personnelles
        // identifiantes sont effacées et le compte est définitivement
        // désactivé (UserChecker bloque désormais toute connexion).
        $id = $user->getId();
        $user->setFirstName('Compte');
        $user->setLastName('supprimé');
        $user->setEmail(sprintf('compte-supprime-%d@moumtou.invalid', $id));
        $user->setPhone('+000000000');
        $user->setWhatsapp(null);
        $user->setWhatsappEnabled(false);
        $user->setPhoto(null);
        $user->setBio(null);
        $user->setLinkedinUrl(null);
        $user->setGithubUrl(null);
        $user->setWebsiteUrl(null);
        $user->setPortfolioUrl(null);
        $user->setCountry(null);
        $user->setCity(null);
        $user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $user->setStatus(UserStatus::SUPPRIME);
        $em->flush();

        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        $this->addFlash('succes', 'Votre compte a été supprimé.');

        return $this->redirectToRoute('app_home');
    }
}
