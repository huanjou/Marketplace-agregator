<?php

declare(strict_types=1);

namespace App\DTO\Search;

use App\DTO\Marketplace\ExternalProductData;

readonly class ProductSearchResult
{
    /**
     * @param ExternalProductData[] $items
     * @param array<string, mixed> $providerMeta
     */
    public function __construct(
        public array $items = [],
        public int $total = 0,
        public ?string $nextCursor = null,
        public array $providerMeta = [],
    ) {}

    public function merge(self $other): self
    {
        return new self(
            items: array_merge($this->items, $other->items),
            total: $this->total + $other->total,
            nextCursor: null,
            providerMeta: array_merge($this->providerMeta, $other->providerMeta),
        );
    }

    /**
     * Slice the requested page out of the full match set held in $items.
     *
     * Search results are cached as the whole sorted set under a
     * page-independent key, so pagination happens here in memory instead of
     * re-running the scrapers on every page click.
     */
    public function forPage(int $page, int $perPage): self
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        return new self(
            items: array_values(array_slice($this->items, $offset, $perPage)),
            total: $this->total,
            nextCursor: $page * $perPage < $this->total ? (string) ($page + 1) : null,
            providerMeta: $this->providerMeta,
        );
    }
}
