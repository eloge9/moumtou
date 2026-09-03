<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Enum\ProjectDocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Stocke les documents déposés pour un projet (cahier des charges —
 * FONCTIONNALITÉ 10 §9) : rapport, présentation, documentation,
 * publication, autre. Réutilise le même répertoire d'upload que
 * {@see ProjectPhotoUploader} (`app.project_uploads_directory`), sous un
 * sous-dossier "documents" — pas d'infrastructure de stockage séparée.
 *
 * Contrairement aux photos, un document n'est jamais recompressé/redimensionné
 * (PDF, Word, PowerPoint) : seule sa provenance (type MIME réel, jamais
 * l'extension fournie par l'utilisateur) détermine le nom de fichier stocké.
 */
class ProjectDocumentUploader
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly string $projectUploadsDirectory,
        private readonly int $maxDocuments,
    ) {
    }

    /**
     * @param UploadedFile[] $files
     */
    public function upload(Project $project, array $files, ?ProjectDocumentType $type, ?string $title): void
    {
        $files = array_slice(array_filter($files), 0, $this->maxDocuments - $project->getDocuments()->count());
        if (!$files) {
            return;
        }

        $documentType = $type ?? ProjectDocumentType::AUTRE;
        $title = trim((string) $title) ?: null;

        $directory = $this->projectDirectory($project);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        foreach ($files as $file) {
            $safeName = strtolower((string) $this->slugger->slug(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME)));
            $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $file->guessExtension());
            $originalFilename = $file->getClientOriginalName();
            $size = $file->getSize() ?: 0;

            $file->move($directory, $filename);

            $document = new ProjectDocument();
            $document->setType($documentType);
            $document->setTitle($title);
            $document->setPath(sprintf('uploads/projects/%d/documents/%s', $project->getId(), $filename));
            $document->setOriginalFilename($originalFilename);
            $document->setSize($size);
            $project->addDocument($document);
        }
    }

    public function delete(Project $project, ProjectDocument $document, EntityManagerInterface $em): void
    {
        $absolutePath = \dirname($this->projectUploadsDirectory, 2).'/'.$document->getPath();
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        $project->removeDocument($document);
        $em->remove($document);
        $em->flush();
    }

    private function projectDirectory(Project $project): string
    {
        return sprintf('%s/%d/documents', $this->projectUploadsDirectory, $project->getId());
    }
}
