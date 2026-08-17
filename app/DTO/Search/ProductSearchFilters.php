<?php

declare(strict_types=1);

namespace App\DTO\Search;

readonly class ProductSearchFilters
{
    /**
     * @param string[] $externalCategoryIds
     * @param string[] $brands
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?int $minPriceAmount = null,
        public ?int $maxPriceAmount = null,
        public ?string $currency = null,
        public ?int $categoryId = null,
        public array $externalCategoryIds = [],
        public array $brands = [],
        public ?float $minRating = null,
        public ?string $availability = null,
        public array $attributes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->minPriceAmount === null
            && $this->maxPriceAmount === null
            && $this->currency === null
            && $this->categoryId === null
            && empty($this->externalCategoryIds)
            && empty($this->brands)
            && $this->minRating === null
            && $this->availability === null
            && empty($this->attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'min_price_amount' => $this->minPriceAmount,
            'max_price_amount' => $this->maxPriceAmount,
            'currency' => $this->currency,
            'category_id' => $this->categoryId,
            'external_category_ids' => $this->externalCategoryIds ?: null,
            'brands' => $this->brands ?: null,
            'min_rating' => $this->minRating,
            'availability' => $this->availability,
            'attributes' => $this->attributes ?: null,
        ], fn ($v) => $v !== null);
    }
}
