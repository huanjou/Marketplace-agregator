<?php

declare(strict_types=1);

namespace App\DTO\Marketplace;

readonly class ProviderCapabilityData
{
    /**
     * @param string[] $supportedFilters
     * @param string[] $supportedSorts
     */
    public function __construct(
        public array $supportedFilters = [],     // e.g. ['price_range', 'category', 'brand', 'availability']
        public array $supportedSorts = [],       // e.g. ['price_asc', 'price_desc', 'relevance']
        public bool $supportsPagination = true,
        public int $maxResultsPerPage = 100,
        public bool $supportsRealtimeSearch = true,
    ) {}

    public function supportsFilter(string $filter): bool
    {
        return in_array($filter, $this->supportedFilters, true);
    }

    public function supportsSort(string $sort): bool
    {
        return in_array($sort, $this->supportedSorts, true);
    }
}
