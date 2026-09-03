<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * En-têtes de sécurité HTTP (cahier des charges — FONCTIONNALITÉ 15 §35) :
 * défense en profondeur au niveau du navigateur, absente jusqu'ici. La
 * politique CSP est construite à partir des ressources externes réellement
 * utilisées par MOUMTOU (audit du 04/09/2026) — pas une politique générique :
 *
 * - cdn.jsdelivr.net : Bootstrap CSS/JS (déjà chargé dans base.html.twig) ;
 * - fonts.googleapis.com / fonts.gstatic.com : police Manrope (Google Fonts) ;
 * - i.ytimg.com : miniatures des vidéos YouTube sur la page projet ;
 * - www.youtube.com : lecteur intégré (iframe créée dynamiquement au clic,
 *   jamais chargée par défaut — cf. public/js/moumtou.js) ;
 * - data: (img-src uniquement) : QR codes générés côté serveur en data URI.
 *
 * 'unsafe-inline' reste nécessaire pour script-src et style-src : de
 * nombreux gabarits Twig existants utilisent des attributs `style="..."`
 * et des gestionnaires `onclick="..."`/`onsubmit="..."` en ligne (ex. copie
 * du lien de partage, confirmation avant suppression). Les retirer
 * demanderait de réécrire une grande partie du frontend existant, ce que le
 * cahier des charges interdit explicitement ("ne refactorise pas
 * inutilement"). La politique reste néanmoins utile : elle bloque le
 * chargement de script/style/image/frame depuis un domaine non whitelisté,
 * qui est le vecteur principal d'exploitation d'une XSS (exfiltration,
 * script distant).
 */
#[AsEventListener(event: 'kernel.response', priority: -10)]
class SecurityHeadersListener
{
    private const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
        ."font-src 'self' https://fonts.gstatic.com; "
        ."img-src 'self' data: https://i.ytimg.com; "
        ."frame-src https://www.youtube.com; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'self'";

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CSP);
        }
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if (!$headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        }

        // HSTS n'a de sens que sur une connexion déjà HTTPS (cahier §36) :
        // l'imposer en HTTP casserait le développement local sans rien
        // sécuriser (l'en-tête est ignoré par les navigateurs en HTTP).
        if ($event->getRequest()->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
