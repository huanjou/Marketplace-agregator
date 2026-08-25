<?php

declare(strict_types=1);

namespace App\DTO\Search;

readonly class ProductSearchQuery
{
    /**
     * @param string[] $providerCodes
     * @param array<string, string> $searchUrls provider code => ready-to-open
     *        marketplace search URL (built by the AI URL service with filters
     *        baked in); providers without an entry fall back to their own
     *        plain-text search URL.
     */
    public function __construct(
        public string $text = '',
        public ProductSearchFilters $filters = new ProductSearchFilters(),
        public ProductSort $sort = new ProductSort(),
        public int $page = 1,
        public int $perPage = 20,
        public array $providerCodes = [],
        public int $timeoutMs = 5000,
        public array $searchUrls = [],
    ) {}

    public function normalized(): self
    {
        $codes = $this->providerCodes;
        sort($codes);

        return new self(
            text: mb_strtolower(trim($this->text)),
            filters: $this->filters,
            sort: $this->sort,
            page: max(1, $this->page),
            perPage: min(100, max(1, $this->perPage)),
            providerCodes: $codes,
            timeoutMs: $this->timeoutMs,
            searchUrls: $this->searchUrls,
        );
    }

    /**
     * Identity of the underlying match set, NOT of the requested page.
     *
     * Results are cached as the whole sorted set and the page is sliced at
     * read time (ProductSearchResult::forPage), so flipping pages must never
     * re-run the scrapers.
     */
    public function cacheFingerprint(): string
    {
        $normalized = $this->normalized();
        $payload = json_encode([
            'text' => $normalized->text,
            'filters' => $normalized->filters->toArray(),
            'sort' => $normalized->sort->field->value . '_' . $normalized->sort->direction,
            'providers' => $normalized->providerCodes,
        ]);

        return hash('sha256', $payload);
    }
}
