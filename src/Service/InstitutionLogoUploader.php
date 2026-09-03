<?php

namespace App\Service;

use App\Entity\Institution;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Même mécanisme que {@see AvatarUploader}, appliqué au logo d'un
 * établissement (cahier des charges — gestion des établissements §2).
 */
class InstitutionLogoUploader
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly string $institutionLogoUploadsDirectory,
    ) {
    }

    public function upload(Institution $institution, UploadedFile $file): void
    {
        if (!is_dir($this->institutionLogoUploadsDirectory)) {
            mkdir($this->institutionLogoUploadsDirectory, 0775, true);
        }

        $safeName = strtolower((string) $this->slugger->slug($institution->getName()));
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $file->guessExtension());

        $file->move($this->institutionLogoUploadsDirectory, $filename);

        $institution->setLogo('uploads/institutions/'.$filename);
    }
}
