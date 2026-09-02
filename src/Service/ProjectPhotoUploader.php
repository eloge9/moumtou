<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectPhoto;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Stocke les photos déposées pour un projet (cahier des charges §20 : JPG,
 * JPEG, PNG, WebP, nombre limité, nom de fichier non fiable).
 *
 * Limitation actuelle : pas de compression/redimensionnement/miniature
 * automatique, l'extension PHP GD n'étant pas activée dans cet environnement
 * (voir point d'étape). Le fichier original validé est stocké tel quel.
 */
class ProjectPhotoUploader
{
    private const MAX_PHOTOS = 8;

    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly string $projectUploadsDirectory,
    ) {
    }

    /**
     * @param UploadedFile[] $files
     */
    public function upload(Project $project, array $files): void
    {
        $files = array_slice(array_filter($files), 0, self::MAX_PHOTOS - $project->getPhotos()->count());
        $directory = sprintf('%s/%d', $this->projectUploadsDirectory, $project->getId());

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $position = $project->getPhotos()->count();

        foreach ($files as $file) {
            $safeName = strtolower((string) $this->slugger->slug(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME)));
            $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $file->guessExtension());

            $file->move($directory, $filename);

            $photo = new ProjectPhoto();
            $photo->setPath(sprintf('uploads/projects/%d/%s', $project->getId(), $filename));
            $photo->setPosition($position++);
            $project->addPhoto($photo);
        }
    }
}
