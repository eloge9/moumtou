<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Entity\ProjectPhoto;
use App\Security\Voter\ProjectVoter;
use App\Service\ProjectDocumentUploader;
use App\Service\ProjectPhotoUploader;
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

/**
 * Gestion post-publication des médias d'un projet (cahier des charges —
 * FONCTIONNALITÉ 10 §2/§3) : réordonner, définir l'image principale,
 * remplacer ou supprimer une photo individuellement, sans avoir à
 * resoumettre l'ensemble de l'assistant de publication. Symétrique pour les
 * documents. Les permissions réutilisent {@see ProjectVoter} — seul le
 * propriétaire du projet ou un administrateur peut agir ici.
 */
#[IsGranted('ROLE_TALENT')]
class ProjectMediaController extends AbstractController
{
    #[Route('/projets/{slug}/photos/{photoId}/principale', name: 'app_project_photo_set_cover', methods: ['POST'], requirements: ['photoId' => '\d+'])]
    public function setCoverPhoto(string $slug, int $photoId, Request $request, EntityManagerInterface $em, ProjectPhotoUploader $photoUploader): Response
    {
        [$project, $photo] = $this->findProjectAndPhoto($slug, $photoId, $em);
        $this->assertCsrf($request, 'photo-principale-'.$photoId);

        $photoUploader->setCover($project, $photo, $em);

        $this->addFlash('succes', 'Image principale mise à jour.');

        return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/photos/{photoId}/deplacer', name: 'app_project_photo_move', methods: ['POST'], requirements: ['photoId' => '\d+'])]
    public function movePhoto(string $slug, int $photoId, Request $request, EntityManagerInterface $em, ProjectPhotoUploader $photoUploader): Response
    {
        [$project, $photo] = $this->findProjectAndPhoto($slug, $photoId, $em);
        $this->assertCsrf($request, 'photo-deplacer-'.$photoId);

        $direction = (string) $request->request->get('direction');
        if (\in_array($direction, ['haut', 'bas'], true)) {
            $photoUploader->move($project, $photo, $direction, $em);
        }

        return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/photos/{photoId}/remplacer', name: 'app_project_photo_replace', methods: ['POST'], requirements: ['photoId' => '\d+'])]
    public function replacePhoto(string $slug, int $photoId, Request $request, EntityManagerInterface $em, ProjectPhotoUploader $photoUploader, ValidatorInterface $validator): Response
    {
        [$project, $photo] = $this->findProjectAndPhoto($slug, $photoId, $em);
        $this->assertCsrf($request, 'photo-remplacer-'.$photoId);

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file) {
            $this->addFlash('erreur', 'Sélectionnez une image à envoyer.');

            return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
        }

        $violations = $validator->validate($file, new File(
            maxSize: '5M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'Formats acceptés : JPG, PNG, WebP.',
            maxSizeMessage: 'Image trop volumineuse. Taille maximale autorisée : {{ limit }} {{ suffix }}.',
        ));
        if (\count($violations) > 0) {
            $this->addFlash('erreur', $violations[0]->getMessage());

            return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
        }

        $photoUploader->replace($project, $photo, $file);
        $em->flush();

        $this->addFlash('succes', 'Image remplacée.');

        return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/photos/{photoId}/supprimer', name: 'app_project_photo_delete', methods: ['POST'], requirements: ['photoId' => '\d+'])]
    public function deletePhoto(string $slug, int $photoId, Request $request, EntityManagerInterface $em, ProjectPhotoUploader $photoUploader): Response
    {
        [$project, $photo] = $this->findProjectAndPhoto($slug, $photoId, $em);
        $this->assertCsrf($request, 'photo-supprimer-'.$photoId);

        $photoUploader->delete($project, $photo, $em);

        $this->addFlash('succes', 'Photo supprimée.');

        return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
    }

    #[Route('/projets/{slug}/documents/{documentId}/supprimer', name: 'app_project_document_delete', methods: ['POST'], requirements: ['documentId' => '\d+'])]
    public function deleteDocument(string $slug, int $documentId, Request $request, EntityManagerInterface $em, ProjectDocumentUploader $documentUploader): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $document = $em->getRepository(ProjectDocument::class)->find($documentId);
        if (!$document || $document->getProject() !== $project) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($request, 'document-supprimer-'.$documentId);

        $documentUploader->delete($project, $document, $em);

        $this->addFlash('succes', 'Document supprimé.');

        return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
    }

    /**
     * Charge le projet et sa photo, vérifie qu'elle lui appartient bien
     * (protection IDOR — cahier des charges §15/§16), et vérifie les
     * permissions.
     *
     * @return array{0: Project, 1: ProjectPhoto}
     */
    private function findProjectAndPhoto(string $slug, int $photoId, EntityManagerInterface $em): array
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $photo = $em->getRepository(ProjectPhoto::class)->find($photoId);
        if (!$photo || $photo->getProject() !== $project) {
            throw $this->createNotFoundException();
        }

        return [$project, $photo];
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }
    }
}
