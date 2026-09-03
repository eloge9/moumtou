<?php

namespace App\Service;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Génère le QR code renvoyant vers la page publique d'un projet ou d'un
 * profil (cahier des charges — FONCTIONNALITÉ 11 §5/§7/§9) : uniquement
 * l'URL publique, jamais les données du projet elles-mêmes. Marge (8) et
 * taille (220px) choisies pour rester lisibles à l'impression ("quiet
 * zone" suffisante — §7). SVG (vectoriel, pour l'impression) et PNG (usage
 * simple, réseaux sociaux) : les deux formats sont générés à partir de la
 * même bibliothèque déjà présente (`endroid/qr-code`), sans dépendance
 * supplémentaire.
 */
class QrCodeGenerator
{
    public function generateSvgDataUri(string $url): string
    {
        $result = (new SvgWriter())->write($this->buildQrCode($url));

        return $result->getDataUri();
    }

    public function generatePngDataUri(string $url): string
    {
        $result = (new PngWriter())->write($this->buildQrCode($url));

        return $result->getDataUri();
    }

    private function buildQrCode(string $url): QrCode
    {
        return new QrCode(
            data: $url,
            size: 220,
            margin: 8,
            foregroundColor: new Color(11, 27, 51),
            backgroundColor: new Color(255, 255, 255),
        );
    }
}
