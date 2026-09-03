<?php

namespace App\Enum;

/**
 * Types d'événements mesurés (cahier des charges — FONCTIONNALITÉ 12 §3/§28) :
 * uniquement des interactions réellement observables côté serveur. Ne
 * couvre pas les favoris/demandes de contact/commentaires/évaluations, déjà
 * suivis par leurs propres entités (§17 : "ne recrée pas les systèmes
 * existants") — ces statistiques sont calculées directement depuis ces
 * entités, pas dupliquées ici.
 */
enum AnalyticsEventType: string
{
    /**
     * Ouverture réelle de la page publique d'un projet. `metadata` vaut
     * 'direct' ou 'qr' selon l'origine (cahier §7/§8).
     */
    case PROJECT_VIEW = 'project_view';

    /** Action de partage déclenchée depuis MOUMTOU (cahier §10). */
    case PROJECT_SHARE = 'project_share';

    /**
     * Clic sortant vers une preuve (GitHub, démo, site, mémoire, autre —
     * cahier §9). `metadata` porte la valeur de {@see ProofType}.
     */
    case PROOF_CLICK = 'proof_click';

    /** Téléchargement du QR code (cahier §11). `metadata` vaut 'svg' ou 'png'. */
    case QR_DOWNLOAD = 'qr_download';

    /** Ouverture de la section vidéo intégrée (cahier §12). */
    case YOUTUBE_OPEN = 'youtube_open';

    /**
     * Recherche filtrée par technologie (cahier §19) : ne porte sur aucun
     * projet précis (`project` vaut null), aucune requête textuelle libre
     * n'est jamais stockée — seul l'identifiant d'une technologie déjà
     * référencée est conservé dans `metadata`, jamais de texte saisi par
     * l'utilisateur ni de lien vers son compte.
     */
    case TECHNOLOGY_SEARCH = 'technology_search';

    public function label(): string
    {
        return match ($this) {
            self::PROJECT_VIEW => 'Vue du projet',
            self::PROJECT_SHARE => 'Partage',
            self::PROOF_CLICK => 'Clic sur une preuve',
            self::QR_DOWNLOAD => 'Téléchargement du QR code',
            self::YOUTUBE_OPEN => 'Ouverture de la vidéo',
            self::TECHNOLOGY_SEARCH => 'Recherche par technologie',
        };
    }
}
