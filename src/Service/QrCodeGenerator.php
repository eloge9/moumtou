<?php

namespace App\Service;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Génère un QR code SVG (pas de dépendance à l'extension GD, indisponible
 * dans cet environnement) renvoyant vers la page publique d'un projet ou
 * d'un profil (cahier des charges §28).
 */
class QrCodeGenerator
{
    public function generateSvgDataUri(string $url): string
    {
        $qrCode = new QrCode(
            data: $url,
            size: 220,
            margin: 8,
            foregroundColor: new Color(11, 27, 51),
            backgroundColor: new Color(255, 255, 255),
        );

        $result = (new SvgWriter())->write($qrCode);

        return $result->getDataUri();
    }
}
