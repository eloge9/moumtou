<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;


class AvatarUploader
{
    private const MAX_DIMENSION = 800;

    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly string $avatarUploadsDirectory,
        private readonly ImageResizer $imageResizer,
    ) {
    }

    public function upload(User $user, UploadedFile $file): void
    {
        if (!is_dir($this->avatarUploadsDirectory)) {
            mkdir($this->avatarUploadsDirectory, 0775, true);
        }

        $safeName = strtolower((string) $this->slugger->slug($user->getSlug() ?? $user->getFullName()));
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $file->guessExtension());

        $file->move($this->avatarUploadsDirectory, $filename);
        $this->imageResizer->resize($this->avatarUploadsDirectory.'/'.$filename, self::MAX_DIMENSION, self::MAX_DIMENSION);

        $user->setPhoto('uploads/avatars/'.$filename);
    }
}
