<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Enums\SearchSortField;

/**
 * Turns the flat list of items harvested from every provider into a single
 * paginated result set: de-duplicate, apply a global sort across providers,
 * then slice the requested page.
 *
 * Sorting has to happen globally (not per provider) — otherwise "cheapest
 * first" would only be true inside each provider's own slice. For the same
 * reason this class is the ONLY place allowed to slice: providers hand over
 * their full match set and merely advertise their totals in providerMeta.
 */
class ResultAggregator
{
    public function __construct(
        private readonly ResultDeduplicator $deduplicator,
    ) {}

    /**
     * @param ExternalProductData[] $items Pre-pagination items from all providers.
     * @param array<string, mixed> $providerMeta Per-provider meta keyed by provider code.
     */
    public function aggregate(
        array $items,
        ProductSearchQuery $query,
        array $providerMeta = [],
    ): ProductSearchResult {
        $rawCount = count($items);

        $deduplicated = $this->deduplicator->deduplicate($items);
        $sorted = $this->sort($deduplicated, $query);

        $deduplicatedCount = count($sorted);
        $total = $this->totalFromProviderMeta($providerMeta) ?? $deduplicatedCount;

        // Providers have already paginated their results based on the requested page.
        // We only take the top N items from the merged set.
        $page = array_values(array_slice($sorted, 0, $query->perPage));

        return new ProductSearchResult(
            items: $page,
            total: $total,
            nextCursor: ($query->page * $query->perPage) < $total
                ? (string) ($query->page + 1)
                : null,
            providerMeta: array_merge($providerMeta, [
                'aggregate' => [
                    'raw' => $rawCount,
                    'deduplicated' => $deduplicatedCount,
                    'duplicates_removed' => $rawCount - $deduplicatedCount,
                    'returned' => count($page),
                    'total' => $total,
                    'sort' => $query->sort->field->value,
                    'page' => $query->page,
                    'per_page' => $query->perPage,
                ],
            ]),
        );
    }

    /**
     * Sum of the true match counts advertised by each provider, or null when no
     * provider reported one (then the caller falls back to the deduplicated
     * count). Non-provider bookkeeping keys are skipped.
     *
     * @param array<string, mixed> $providerMeta
     */
    private function totalFromProviderMeta(array $providerMeta): ?int
    {
        $total = 0;
        $seen = false;

        foreach ($providerMeta as $code => $meta) {
            if ($code === 'aggregate' || $code === 'cache' || ! is_array($meta)) {
                continue;
            }

            if (! is_numeric($meta['total'] ?? null)) {
                continue;
            }

            $total += (int) $meta['total'];
            $seen = true;
        }

        return $seen && $total > 0 ? $total : null;
    }

    /**
     * @param ExternalProductData[] $items
     * @return ExternalProductData[]
     */
    private function sort(array $items, ProductSearchQuery $query): array
    {
        $text = $query->text;

        // usort() is stable as of PHP 8.0, so items that compare equal keep the
        // order in which their provider returned them.
        $comparator = match ($query->sort->field) {
            SearchSortField::PriceAsc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => ($a->priceAmount ?? PHP_INT_MAX) <=> ($b->priceAmount ?? PHP_INT_MAX),

            SearchSortField::PriceDesc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => ($b->priceAmount ?? PHP_INT_MIN) <=> ($a->priceAmount ?? PHP_INT_MIN),

            SearchSortField::RatingDesc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => [$b->ratingValue ?? -1.0, $b->ratingCount ?? 0]
                <=> [$a->ratingValue ?? -1.0, $a->ratingCount ?? 0],

            // Providers already return their freshest offers first and external
            // ids carry no reliable timestamp, so "newest" preserves the order
            // we received.
            SearchSortField::Newest => null,

            SearchSortField::Relevance => $text === ''
                ? null
                : fn (ExternalProductData $a, ExternalProductData $b): int
                    => $this->relevanceScore($b, $text) <=> $this->relevanceScore($a, $text),
        };

        if ($comparator !== null) {
            usort($items, $comparator);
        }

        // For the order-preserving sorts an explicit ascending direction is the
        // only way for a caller to flip the provider order.
        if ($comparator === null && strtolower($query->sort->direction) === 'asc') {
            $items = array_reverse($items);
        }

        return array_values($items);
    }

    /**
     * Cheap cross-provider text score: title hits outrank brand hits. Providers
     * have already filtered by relevance, this only keeps the merged list sane.
     */
    private function relevanceScore(ExternalProductData $item, string $text): int
    {
        $needle = mb_strtolower(trim($text));
        $title = mb_strtolower($item->title);
        $brand = mb_strtolower((string) $item->brand);

        if ($title === $needle) {
            return 100;
        }

        $score = 0;

        if (str_starts_with($title, $needle)) {
            $score = 80;
        } elseif (str_contains($title, $needle)) {
            $score = 60;
        }

        if ($brand !== '' && str_contains($brand, $needle)) {
            $score = max($score, 40);
        }

        return $score;
    }
}
