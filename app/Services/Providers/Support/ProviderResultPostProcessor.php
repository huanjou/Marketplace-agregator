<?php

declare(strict_types=1);

namespace App\Services\Providers\Support;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\Enums\Availability;
use App\Enums\SearchSortField;

/**
 * Applies the parts of a ProductSearchQuery that a marketplace API cannot
 * express natively.
 *
 * Both seller-side APIs we integrate with (Ozon Seller, Yandex Market Partner)
 * are catalogue APIs rather than search engines: they offer no free-text query
 * and only a subset of the filters/sorts this application exposes. Whatever the
 * remote side cannot do is done here, in memory, over the page of rows the
 * provider fetched.
 */
class ProviderResultPostProcessor
{
    /**
     * @param ExternalProductData[] $items
     * @return ExternalProductData[]
     */
    public function filter(array $items, ProductSearchQuery $query): array
    {
        $filters = $query->filters;
        $text = trim(mb_strtolower($query->text));

        return array_values(array_filter($items, function (ExternalProductData $item) use ($filters, $text): bool {
            if ($text !== '' && $this->relevanceScore($item, $text) === 0) {
                return false;
            }

            if ($filters->minPriceAmount !== null
                && ($item->priceAmount === null || $item->priceAmount < $filters->minPriceAmount)) {
                return false;
            }

            if ($filters->maxPriceAmount !== null
                && ($item->priceAmount === null || $item->priceAmount > $filters->maxPriceAmount)) {
                return false;
            }

            if ($filters->currency !== null
                && mb_strtoupper($item->currency) !== mb_strtoupper($filters->currency)) {
                return false;
            }

            if ($filters->brands !== [] && ! $this->matchesBrand($item->brand, $filters->brands)) {
                return false;
            }

            if ($filters->externalCategoryIds !== []
                && ! in_array((string) $item->categoryExternalId, array_map('strval', $filters->externalCategoryIds), true)) {
                return false;
            }

            if ($filters->minRating !== null
                && ($item->ratingValue === null || $item->ratingValue < $filters->minRating)) {
                return false;
            }

            // An 'unknown' status means the endpoint simply does not report
            // stock, so it is not evidence against the offer: filtering it out
            // would silently empty every result for such providers.
            if ($filters->availability !== null
                && $item->availabilityStatus !== null
                && $item->availabilityStatus !== Availability::Unknown->value
                && $item->availabilityStatus !== $filters->availability) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param ExternalProductData[] $items
     * @return ExternalProductData[]
     */
    public function sort(array $items, ProductSearchQuery $query): array
    {
        $text = trim(mb_strtolower($query->text));

        $comparator = match ($query->sort->field) {
            SearchSortField::PriceAsc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => ($a->priceAmount ?? PHP_INT_MAX) <=> ($b->priceAmount ?? PHP_INT_MAX),
            SearchSortField::PriceDesc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => ($b->priceAmount ?? PHP_INT_MIN) <=> ($a->priceAmount ?? PHP_INT_MIN),
            SearchSortField::RatingDesc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => [$b->ratingValue ?? 0.0, $b->ratingCount ?? 0] <=> [$a->ratingValue ?? 0.0, $a->ratingCount ?? 0],
            SearchSortField::Newest => static fn (ExternalProductData $a, ExternalProductData $b): int
                => strcmp((string) $b->externalProductId, (string) $a->externalProductId),
            SearchSortField::Relevance => fn (ExternalProductData $a, ExternalProductData $b): int
                => [$this->relevanceScore($b, $text), $b->ratingValue ?? 0.0]
                <=> [$this->relevanceScore($a, $text), $a->ratingValue ?? 0.0],
        };

        usort($items, $comparator);

        return $items;
    }

    /**
     * Same shape of heuristic as the demo provider: exact hits outrank prefix
     * hits, which outrank substring and brand hits. 0 means "no match".
     */
    private function relevanceScore(ExternalProductData $item, string $text): int
    {
        if ($text === '') {
            return 1;
        }

        $title = mb_strtolower($item->title);
        $brand = mb_strtolower((string) $item->brand);

        if ($title === $text) {
            return 100;
        }

        $score = 0;

        if (str_starts_with($title, $text)) {
            $score = 80;
        } elseif (str_contains($title, $text)) {
            $score = 60;
        }

        if ($brand !== '' && $brand === $text) {
            $score = max($score, 70);
        } elseif ($brand !== '' && str_contains($brand, $text)) {
            $score = max($score, 40);
        }

        return $score;
    }

    /**
     * Brands are matched loosely (substring, case-insensitive) because seller
     * catalogues spell vendors inconsistently ("Sony" vs "SONY Corp.").
     *
     * @param string[] $candidates
     */
    private function matchesBrand(?string $value, array $candidates): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $value = mb_strtolower($value);

        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));

            if ($candidate !== '' && str_contains($value, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
