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
}
