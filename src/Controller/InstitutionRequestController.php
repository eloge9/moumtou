<?php

namespace App\Controller;

use App\Entity\InstitutionRequest;
use App\Entity\User;
use App\Enum\InstitutionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mon établissement n'est pas dans la liste » (gestion des
 * établissements §4/§13) : accessible à tout compte connecté (talent,
 * enseignant...), redirige toujours vers la page d'où vient la demande.
 */
#[IsGranted('ROLE_USER')]
class InstitutionRequestController extends AbstractController
{
    #[Route('/etablissements/demander', name: 'app_institution_request_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('demande-etablissement', $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $name = trim((string) $request->request->get('name'));
        $redirectUrl = $this->sanitizeRedirect((string) $request->request->get('redirect'));

        if (!$name) {
            $this->addFlash('erreur', 'Veuillez indiquer le nom de l\'établissement.');

            return $this->redirect($redirectUrl);
        }

        $institutionRequest = new InstitutionRequest();
        /** @var User $user */
        $user = $this->getUser();
        $institutionRequest->setRequestedBy($user);
        $institutionRequest->setName($name);
        $institutionRequest->setType(InstitutionType::tryFrom((string) $request->request->get('type')) ?? InstitutionType::AUTRE);
        $institutionRequest->setCountry(trim((string) $request->request->get('country')) ?: null);
        $institutionRequest->setCity(trim((string) $request->request->get('city')) ?: null);
        $institutionRequest->setAddress(trim((string) $request->request->get('address')) ?: null);
        $institutionRequest->setWebsite(trim((string) $request->request->get('website')) ?: null);
        $institutionRequest->setAdditionalInfo(trim((string) $request->request->get('additional_info')) ?: null);
        $em->persist($institutionRequest);
        $em->flush();

        $this->addFlash('succes', 'Votre demande a été envoyée à l\'administration.');

        return $this->redirect($redirectUrl);
    }

    /**
     * N'accepte qu'un chemin relatif interne, pour éviter toute redirection
     * ouverte vers un domaine externe via le champ "redirect" du formulaire.
     */
    private function sanitizeRedirect(string $redirect): string
    {
        if ($redirect && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            return $redirect;
        }

        return $this->generateUrl('app_home');
    }
}
