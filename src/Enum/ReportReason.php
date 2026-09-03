<?php

namespace App\Enum;

/**
 * Motifs de signalement listés au cahier des charges §31.
 */
enum ReportReason: string
{
    case DEMANDE_FINANCEMENT = 'demande_financement';
    case FAUX_PROJET = 'faux_projet';
    case CONTENU_FRAUDULEUX = 'contenu_frauduleux';
    case CONTENU_OFFENSANT = 'contenu_offensant';
    case HARCELEMENT = 'harcelement';
    case SPAM = 'spam';
    case USURPATION_IDENTITE = 'usurpation_identite';
    case CONTENU_INTERDIT = 'contenu_interdit';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::DEMANDE_FINANCEMENT => 'Demande de financement',
            self::FAUX_PROJET => 'Faux projet',
            self::CONTENU_FRAUDULEUX => 'Contenu frauduleux / fausse information',
            self::CONTENU_OFFENSANT => 'Contenu offensant',
            self::HARCELEMENT => 'Harcèlement',
            self::SPAM => 'Spam',
            self::USURPATION_IDENTITE => 'Usurpation d\'identité',
            self::CONTENU_INTERDIT => 'Contenu interdit / inapproprié',
            self::AUTRE => 'Autre',
        };
    }
}
