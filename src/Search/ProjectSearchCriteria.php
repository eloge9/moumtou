<?php

namespace App\Search;

/**
 * Filtres de la page Explorer (code/explorer.html). Uniquement des scalaires
 * simples pour rester facile à construire depuis la query string et à tester.
 */
final class ProjectSearchCriteria
{
    public const SORT_RECENT = 'recent';
    public const SORT_RATING = 'rating';
    public const SORT_VIEWS = 'views';

    /** @param string[] $types @param string[] $statuses @param int[] $technologyIds */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $types = [],
        public readonly ?int $domainId = null,
        public readonly ?int $mentionId = null,
        public readonly ?int $specialtyId = null,
        public readonly array $technologyIds = [],
        public readonly array $statuses = [],
        public readonly ?int $institutionId = null,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?int $yearMin = null,
        public readonly string $sort = self::SORT_RECENT,
        public readonly int $page = 1,
        public readonly int $perPage = 9,
    ) {
    }
}
