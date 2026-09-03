<?php

namespace App\Service;

/**
 * MOUMTOU n'est pas une plateforme de financement participatif (cahier des
 * charges §4/§8/§32) : les projets qui ne sont qu'une recherche de
 * financement/investissement/crowdfunding/contributions sont interdits en
 * V1, au même titre qu'une "idée non réalisée" (déjà bloquée par
 * l'obligation de preuve). Cette détection est une heuristique par
 * mots-clés — volontairement limitée à des expressions peu ambiguës pour
 * éviter de bloquer à tort un projet qui, par exemple, mentionne avoir
 * *déjà obtenu* un financement passé. Le porteur reste libre de reformuler
 * et de soumettre à nouveau ; ce n'est pas une sanction sur le compte.
 */
class ForbiddenContentDetector
{
    /** @var string[] */
    private const FORBIDDEN_PATTERNS = [
        // Financement / investissement
        'recherch(?:e|ons|e de) (?:un )?(?:financement|investisseur|investissement)',
        'lev[ée]e? de fonds',
        'cherch(?:e|ons) (?:un |des )?investisseur',
        'appel aux investisseurs',
        'seeking (?:funding|investors?|investment)',
        'fundraising',
        // Crowdfunding / dons
        'crowdfunding',
        'cagnotte (?:en ligne|participative)',
        'financement participatif',
        'faites? un don',
        'faire un don',
        'donations? (?:bienvenues?|acceptées?)',
        'soutenez (?:ce |mon |notre )?projet financièrement',
        // Contributions financières
        'contribution(?:s)? financière',
        'demande(?:z)? une contribution',
    ];

    /**
     * @param string[] $texts
     *
     * @return string|null le motif détecté (pour un message d'erreur clair), ou null si rien de suspect
     */
    public function detect(array $texts): ?string
    {
        $haystack = mb_strtolower(implode(' ', array_filter($texts)));
        if ('' === trim($haystack)) {
            return null;
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match('#'.$pattern.'#ui', $haystack)) {
                return $pattern;
            }
        }

        return null;
    }
}
