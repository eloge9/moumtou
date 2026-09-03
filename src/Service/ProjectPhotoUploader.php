<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Stocke les photos déposées pour un projet (cahier des charges §20 : JPG,
 * JPEG, PNG, WebP, nombre limité, nom de fichier non fiable, compression et
 * redimensionnement via {@see ImageResizer}), et gère leur cycle de vie
 * complet (suppression, réorganisation, image principale, remplacement —
 * FONCTIONNALITÉ 10 §2/§3).
 *
 * Convention réutilisée dans tout MOUMTOU (explorer, recherche, profil,
 * établissement, page projet) : l'image principale/couverture est toujours
 * la photo de plus petite `position` (0), jamais un champ séparé — ainsi
 * "définir comme principale" se résume à réordonner les positions.
 */
class ProjectPhotoUploader
{
    private const MAX_DIMENSION = 1600;
    private const THUMBNAIL_DIMENSION = 320;

    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly string $projectUploadsDirectory,
        private readonly ImageResizer $imageResizer,
        private readonly int $maxPhotos,
    ) {
    }

    /**
     * @param UploadedFile[] $files
     */
    public function upload(Project $project, array $files): void
    {
        $files = array_slice(array_filter($files), 0, $this->maxPhotos - $project->getPhotos()->count());
        $directory = $this->projectDirectory($project);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $position = $project->getPhotos()->count();

        foreach ($files as $file) {
            [$filename, $thumbnailFilename] = $this->storeFile($file, $directory);

            $photo = new ProjectPhoto();
            $photo->setPath(sprintf('uploads/projects/%d/%s', $project->getId(), $filename));
            if ($thumbnailFilename) {
                $photo->setThumbnailPath(sprintf('uploads/projects/%d/%s', $project->getId(), $thumbnailFilename));
            }
            $photo->setPosition($position++);
            $project->addPhoto($photo);
        }
    }

    /**
     * Supprime une photo : entité, fichier original et miniature.
     */
    public function delete(Project $project, ProjectPhoto $photo, EntityManagerInterface $em): void
    {
        $this->deletePhysicalFiles($photo);
        $project->removePhoto($photo);
        $em->remove($photo);
        $em->flush();

        $this->resequencePositions($project, $em);
    }

    /**
     * Remplace le fichier d'une photo existante en conservant sa position
     * (et donc son statut d'image principale si elle l'était) — cahier des
     * charges §2 : "remplacer une image".
     */
    public function replace(Project $project, ProjectPhoto $photo, UploadedFile $file): void
    {
        $this->deletePhysicalFiles($photo);

        $directory = $this->projectDirectory($project);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        [$filename, $thumbnailFilename] = $this->storeFile($file, $directory);

        $photo->setPath(sprintf('uploads/projects/%d/%s', $project->getId(), $filename));
        $photo->setThumbnailPath($thumbnailFilename ? sprintf('uploads/projects/%d/%s', $project->getId(), $thumbnailFilename) : null);
    }

    /**
     * Définit une photo comme image principale/couverture : elle passe en
     * position 0, les autres sont décalées d'un cran en conservant leur
     * ordre relatif.
     */
    public function setCover(Project $project, ProjectPhoto $cover, EntityManagerInterface $em): void
    {
        $photos = $project->getPhotos()->toArray();
        usort($photos, static fn (ProjectPhoto $a, ProjectPhoto $b) => $a->getPosition() <=> $b->getPosition());

        $position = 1;
        foreach ($photos as $photo) {
            if ($photo === $cover) {
                continue;
            }
            $photo->setPosition($position++);
        }
        $cover->setPosition(0);

        $em->flush();
    }

    /**
     * Déplace une photo d'un cran vers le haut (-1) ou le bas (+1) dans
     * l'ordre d'affichage, en échangeant sa position avec sa voisine —
     * cahier des charges §2 : "modifier l'ordre des images".
     */
    public function move(Project $project, ProjectPhoto $photo, string $direction, EntityManagerInterface $em): void
    {
        $photos = $project->getPhotos()->toArray();
        usort($photos, static fn (ProjectPhoto $a, ProjectPhoto $b) => $a->getPosition() <=> $b->getPosition());

        $index = array_search($photo, $photos, true);
        if (false === $index) {
            return;
        }

        $targetIndex = 'haut' === $direction ? $index - 1 : $index + 1;
        if ($targetIndex < 0 || $targetIndex >= \count($photos)) {
            return;
        }

        $neighbor = $photos[$targetIndex];
        $tmp = $photo->getPosition();
        $photo->setPosition($neighbor->getPosition());
        $neighbor->setPosition($tmp);

        $em->flush();
    }

    private function projectDirectory(Project $project): string
    {
        return sprintf('%s/%d', $this->projectUploadsDirectory, $project->getId());
    }

    /**
     * @return array{0: string, 1: ?string} nom du fichier stocké et, si
     *                                       généré, nom de sa miniature
     */
    private function storeFile(UploadedFile $file, string $directory): array
    {
        $safeName = strtolower((string) $this->slugger->slug(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME)));
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $file->guessExtension());

        $file->move($directory, $filename);
        $this->imageResizer->resize($directory.'/'.$filename, self::MAX_DIMENSION, self::MAX_DIMENSION);

        $thumbnailFilename = 'thumb-'.$filename;
        $created = $this->imageResizer->createThumbnail($directory.'/'.$filename, $directory.'/'.$thumbnailFilename, self::THUMBNAIL_DIMENSION);

        return [$filename, $created ? $thumbnailFilename : null];
    }

    private function deletePhysicalFiles(ProjectPhoto $photo): void
    {
        foreach ([$photo->getPath(), $photo->getThumbnailPath()] as $relativePath) {
            if (!$relativePath) {
                continue;
            }
            $absolutePath = \dirname($this->projectUploadsDirectory, 2).'/'.$relativePath;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    /**
     * Referme les trous laissés par une suppression (0, 1, 3 → 0, 1, 2) :
     * évite que la position 0 disparaisse et casse la convention "image
     * principale = position la plus basse" utilisée dans tout MOUMTOU.
     */
    private function resequencePositions(Project $project, EntityManagerInterface $em): void
    {
        $photos = $project->getPhotos()->toArray();
        usort($photos, static fn (ProjectPhoto $a, ProjectPhoto $b) => $a->getPosition() <=> $b->getPosition());

        foreach ($photos as $index => $photo) {
            $photo->setPosition($index);
        }

        $em->flush();
    }
}
