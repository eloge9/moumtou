<?php

namespace App\Controller\Admin;

use App\Entity\Institution;
use App\Enum\InstitutionType;
use App\Service\InstitutionLogoUploader;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/etablissements')]
class InstitutionController extends AbstractController
{
    #[Route('', name: 'admin_institutions')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $type = InstitutionType::tryFrom((string) $request->query->get('type', ''));
        $verifiedFilter = $request->query->get('verified', '');

        $qb = $em->getRepository(Institution::class)->createQueryBuilder('i')->orderBy('i.name', 'ASC');

        if ($query) {
            $qb->andWhere('i.name LIKE :q OR i.city LIKE :q OR i.country LIKE :q')->setParameter('q', '%'.$query.'%');
        }
        if ($type) {
            $qb->andWhere('i.type = :type')->setParameter('type', $type);
        }
        if ('verifie' === $verifiedFilter) {
            $qb->andWhere('i.verified = true');
        } elseif ('non_verifie' === $verifiedFilter) {
            $qb->andWhere('i.verified = false');
        }

        return $this->render('admin/institutions.html.twig', [
            'adminNav' => 'institutions',
            'institutions' => $qb->getQuery()->getResult(),
            'institutionTypes' => InstitutionType::cases(),
            'query' => $query,
            'type' => $type,
            'verifiedFilter' => $verifiedFilter,
            'pendingRequestsCount' => $em->getRepository(\App\Entity\InstitutionRequest::class)
                ->count(['status' => \App\Enum\InstitutionRequestStatus::EN_ATTENTE]),
        ]);
    }

    #[Route('/ajouter', name: 'admin_institutions_add', methods: ['POST'])]
    public function add(Request $request, EntityManagerInterface $em, InstitutionLogoUploader $logoUploader, ValidatorInterface $validator, SlugGenerator $slugGenerator): Response
    {
        if (!$this->isCsrfTokenValid('admin-institution-ajouter', $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $name = trim((string) $request->request->get('name'));
        if (!$name) {
            $this->addFlash('erreur', 'Le nom de l\'établissement est obligatoire.');

            return $this->redirectToRoute('admin_institutions');
        }

        if ($em->getRepository(Institution::class)->findOneBy(['name' => $name])) {
            $this->addFlash('erreur', 'Cet établissement est déjà enregistré.');

            return $this->redirectToRoute('admin_institutions');
        }

        $institution = new Institution();
        $institution->setName($name);
        $institution->setSlug($slugGenerator->generateUnique($name, Institution::class));
        $institution->setType(InstitutionType::tryFrom((string) $request->request->get('type')) ?? InstitutionType::AUTRE);
        $institution->setCountry(trim((string) $request->request->get('country')) ?: null);
        $institution->setCity(trim((string) $request->request->get('city')) ?: null);
        $institution->setWebsite(trim((string) $request->request->get('website')) ?: null);
        $institution->setVerified(true);
        $em->persist($institution);

        $logoError = $this->validateAndUploadLogo($request, $institution, $logoUploader, $validator);
        if ($logoError) {
            $this->addFlash('erreur', $logoError);

            return $this->redirectToRoute('admin_institutions');
        }

        $em->flush();

        $this->addFlash('succes', 'Établissement ajouté avec succès.');

        return $this->redirectToRoute('admin_institutions');
    }

    #[Route('/{id}/modifier', name: 'admin_institutions_edit', methods: ['POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $em, InstitutionLogoUploader $logoUploader, ValidatorInterface $validator): Response
    {
        $institution = $em->getRepository(Institution::class)->find($id);
        if (!$institution) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('admin-institution-modifier-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $name = trim((string) $request->request->get('name'));
        if (!$name) {
            $this->addFlash('erreur', 'Le nom de l\'établissement est obligatoire.');

            return $this->redirectToRoute('admin_institutions');
        }

        $institution->setName($name);
        $institution->setType(InstitutionType::tryFrom((string) $request->request->get('type')) ?? $institution->getType());
        $institution->setCountry(trim((string) $request->request->get('country')) ?: null);
        $institution->setCity(trim((string) $request->request->get('city')) ?: null);
        $institution->setAddress(trim((string) $request->request->get('address')) ?: null);
        $institution->setWebsite(trim((string) $request->request->get('website')) ?: null);
        $institution->setDescription(trim((string) $request->request->get('description')) ?: null);
        $institution->setUpdatedAt(new \DateTimeImmutable());

        $logoError = $this->validateAndUploadLogo($request, $institution, $logoUploader, $validator);
        if ($logoError) {
            $this->addFlash('erreur', $logoError);

            return $this->redirectToRoute('admin_institutions');
        }

        $em->flush();

        $this->addFlash('succes', 'Établissement modifié avec succès.');

        return $this->redirectToRoute('admin_institutions');
    }

    #[Route('/{id}/verifier', name: 'admin_institutions_toggle_verified', methods: ['POST'])]
    public function toggleVerified(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $institution = $em->getRepository(Institution::class)->find($id);
        if (!$institution) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('admin-institution-verifier-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $institution->setVerified(!$institution->isVerified());
        $institution->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->redirectToRoute('admin_institutions');
    }

    #[Route('/{id}/desactiver', name: 'admin_institutions_toggle_active', methods: ['POST'])]
    public function toggleActive(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $institution = $em->getRepository(Institution::class)->find($id);
        if (!$institution) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('admin-institution-desactiver-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $institution->setActive(!$institution->isActive());
        $institution->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('succes', $institution->isActive() ? 'Établissement réactivé.' : 'Établissement désactivé : il n\'apparaîtra plus dans les listes de sélection.');

        return $this->redirectToRoute('admin_institutions');
    }

    #[Route('/{id}/supprimer', name: 'admin_institutions_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $institution = $em->getRepository(Institution::class)->find($id);
        if (!$institution) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('admin-institution-supprimer-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        try {
            $em->remove($institution);
            $em->flush();
            $this->addFlash('succes', 'Établissement supprimé.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            $em->clear();
            $this->addFlash('erreur', 'Cet établissement est utilisé par au moins un projet, un rattachement ou un membre de jury et ne peut pas être supprimé. Désactivez-le plutôt.');
        }

        return $this->redirectToRoute('admin_institutions');
    }

    /**
     * Valide réellement le fichier envoyé côté serveur (type MIME, taille) —
     * l'attribut HTML `accept` n'est qu'un confort côté navigateur et ne
     * protège de rien. Retourne un message d'erreur, ou null si tout est
     * en ordre (fichier absent ou valide et téléversé).
     */
    private function validateAndUploadLogo(Request $request, Institution $institution, InstitutionLogoUploader $logoUploader, ValidatorInterface $validator): ?string
    {
        /** @var UploadedFile|null $logo */
        $logo = $request->files->get('logo');
        if (!$logo) {
            return null;
        }

        $violations = $validator->validate($logo, new File(
            maxSize: '3M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'Formats acceptés pour le logo : JPG, PNG, WebP.',
        ));

        if (\count($violations) > 0) {
            return $violations[0]->getMessage();
        }

        $logoUploader->upload($institution, $logo);

        return null;
    }
}
