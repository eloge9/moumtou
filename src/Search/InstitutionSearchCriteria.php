<?php

namespace App\Search;

/**
 * Filtres de l'annuaire public des établissements (nouvelle fonctionnalité
 * « Établissements »), même convention que {@see ProjectSearchCriteria} et
 * {@see TalentSearchCriteria} : scalaires simples, faciles à construire
 * depuis la query string et à tester.
 */
final class InstitutionSearchCriteria
{
    public const MAX_PER_PAGE = 50;

    public function __construct(
        public readonly ?string $query = null,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly int $page = 1,
        public readonly int $perPage = 12,
    ) {
    }
}
