<?php

namespace App\Service;

use App\Entity\RecruiterProfile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Même mécanisme que {@see InstitutionLogoUploader}, appliqué au logo d'une
 * organisation recruteur (cahier des charges — FONCTIONNALITÉ 7 §4).
 */
class RecruiterLogoUploader
{
    private const MAX_DIMENSION = 400;

    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly string $recruiterLogoUploadsDirectory,
        private readonly ImageResizer $imageResizer,
    ) {
    }

    public function upload(RecruiterProfile $profile, UploadedFile $file): void
    {
        if (!is_dir($this->recruiterLogoUploadsDirectory)) {
            mkdir($this->recruiterLogoUploadsDirectory, 0775, true);
        }

        $safeName = strtolower((string) $this->slugger->slug($profile->getOrganizationName() ?: 'organisation'));
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $file->guessExtension());

        $file->move($this->recruiterLogoUploadsDirectory, $filename);
        $this->imageResizer->resize($this->recruiterLogoUploadsDirectory.'/'.$filename, self::MAX_DIMENSION, self::MAX_DIMENSION);

        $profile->setLogo('uploads/recruiters/'.$filename);
    }
}
