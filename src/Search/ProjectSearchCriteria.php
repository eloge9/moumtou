<?php

namespace App\Search;

/**
 * Filtres de la page Explorer (code/explorer.html). Uniquement des scalaires
 * simples pour rester facile à construire depuis la query string et à tester.
 */
final class ProjectSearchCriteria
{
    public const SORT_RECENT = 'recent';
    public const SORT_OLDEST = 'oldest';
    public const SORT_RATING = 'rating';
    public const SORT_VIEWS = 'views';
    public const SORT_RELEVANCE = 'relevance';

    public const TECH_MODE_ANY = 'any';
    public const TECH_MODE_ALL = 'all';

    public const MAX_PER_PAGE = 50;

    /** @param string[] $types @param string[] $statuses @param int[] $technologyIds */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $types = [],
        public readonly ?int $domainId = null,
        public readonly ?int $mentionId = null,
        public readonly ?int $specialtyId = null,
        public readonly array $technologyIds = [],
        public readonly string $techMode = self::TECH_MODE_ANY,
        public readonly array $statuses = [],
        public readonly ?int $institutionId = null,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?int $yearMin = null,
        public readonly bool $defenseVerified = false,
        public readonly string $sort = self::SORT_RECENT,
        public readonly int $page = 1,
        public readonly int $perPage = 9,
    ) {
    }
}
