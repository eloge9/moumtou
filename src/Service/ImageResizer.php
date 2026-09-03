<?php

namespace App\Service;

/**
 * Redimensionnement et compression des images téléversées (cahier des
 * charges §20 : "compression, redimensionnement"). Réduit uniquement les
 * images plus grandes que le maximum demandé — ne redimensionne jamais vers
 * le haut — et réencode dans le même format avec une qualité raisonnable.
 *
 * S'appuie sur l'extension GD ; si elle n'est pas chargée, l'image
 * d'origine est conservée telle quelle plutôt que de faire planter l'upload
 * (dégradation silencieuse, cf. cahier §48 : ne jamais bloquer sur une
 * dépendance externe optionnelle).
 */
class ImageResizer
{
    public function resize(string $absolutePath, int $maxWidth, int $maxHeight, int $quality = 85): void
    {
        if (!\extension_loaded('gd') || !is_file($absolutePath)) {
            return;
        }

        $info = @getimagesize($absolutePath);
        if (!$info) {
            return;
        }

        [$width, $height, $type] = $info;

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return; // déjà assez petite : on ne recompresse pas inutilement.
        }

        $source = match ($type) {
            \IMAGETYPE_JPEG => imagecreatefromjpeg($absolutePath),
            \IMAGETYPE_PNG => imagecreatefrompng($absolutePath),
            \IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') ? imagecreatefromwebp($absolutePath) : null,
            default => null,
        };

        if (!$source) {
            return;
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        if (\IMAGETYPE_PNG === $type || \IMAGETYPE_WEBP === $type) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        match ($type) {
            \IMAGETYPE_JPEG => imagejpeg($resized, $absolutePath, $quality),
            \IMAGETYPE_PNG => imagepng($resized, $absolutePath, (int) round((100 - $quality) / 10)),
            \IMAGETYPE_WEBP => \function_exists('imagewebp') ? imagewebp($resized, $absolutePath, $quality) : null,
            default => null,
        };

        imagedestroy($source);
        imagedestroy($resized);
    }
}
