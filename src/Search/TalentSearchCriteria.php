<?php

namespace App\Search;

/**
 * Filtres de recherche de talents (recherche générale publique et espace
 * recruteur), symétrique de {@see ProjectSearchCriteria}. Uniquement des
 * scalaires simples pour rester facile à construire depuis la query string.
 */
final class TalentSearchCriteria
{
    public const SORT_RECENT = 'recent';
    public const SORT_NAME = 'name';
    public const SORT_RELEVANCE = 'relevance';

    public const TECH_MODE_ANY = 'any';
    public const TECH_MODE_ALL = 'all';

    public const MAX_PER_PAGE = 50;

    /**
     * @param int[]    $technologyIds
     * @param int[]    $skillIds
     * @param string[] $projectTypes
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $technologyIds = [],
        public readonly string $techMode = self::TECH_MODE_ANY,
        public readonly array $skillIds = [],
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?int $institutionId = null,
        public readonly ?int $domainId = null,
        public readonly ?int $mentionId = null,
        public readonly ?int $specialtyId = null,
        public readonly array $projectTypes = [],
        public readonly ?int $yearMin = null,
        public readonly ?string $availability = null,
        public readonly string $sort = self::SORT_RELEVANCE,
        public readonly int $page = 1,
        public readonly int $perPage = 12,
    ) {
    }
}
