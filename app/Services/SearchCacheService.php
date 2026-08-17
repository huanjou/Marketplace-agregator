<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Models\SearchCache;
use App\Models\SearchCacheItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two-tier storage for finished search results.
 *
 * Redis is the source of truth used on the hot path; the search_caches /
 * search_cache_items tables are a best-effort mirror that lets the admin panel
 * inspect what was served and survives a Redis flush for analytics purposes.
 * A failing mirror must never fail a search.
 */
class SearchCacheService
{
    public function get(ProductSearchQuery $query): ?ProductSearchResult
    {
        $payload = Cache::get($this->cacheKey($query));

        if (! is_array($payload)) {
            return null;
        }

        return $this->hydrateResult($payload);
    }

    public function put(ProductSearchQuery $query, ProductSearchResult $result, int $ttlSeconds): void
    {
        $key = $this->cacheKey($query);
        $fingerprint = $query->cacheFingerprint();

        Cache::put($key, $this->serializeResult($result), $ttlSeconds);

        $this->mirrorToDatabase($fingerprint, $query, $result, $ttlSeconds);
    }

    public function forget(ProductSearchQuery $query): void
    {
        Cache::forget($this->cacheKey($query));

        try {
            SearchCache::query()
                ->where('cache_key', $query->cacheFingerprint())
                ->delete();
        } catch (Throwable $e) {
            Log::warning('Failed to drop mirrored search cache row.', [
                'cache_key' => $query->cacheFingerprint(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function cacheKey(ProductSearchQuery $query): string
    {
        return config('marketplace.search.cache_prefix', 'product-search')
            . ':' . $query->cacheFingerprint();
    }

    /**
     * Flatten an ExternalProductData into a plain array (Redis payloads and
     * jsonb snapshots share the same shape).
     *
     * @return array<string, mixed>
     */
    public function serializeItem(ExternalProductData $item): array
    {
        return [
            'provider_code' => $item->providerCode,
            'external_product_id' => $item->externalProductId,
            'external_offer_id' => $item->externalOfferId,
            'title' => $item->title,
            'brand' => $item->brand,
            'description' => $item->description,
            'price_amount' => $item->priceAmount,
            'old_price_amount' => $item->oldPriceAmount,
            'currency' => $item->currency,
            'category_external_id' => $item->categoryExternalId,
            'category_name' => $item->categoryName,
            'image_urls' => $item->imageUrls,
            'product_url' => $item->productUrl,
            'availability_status' => $item->availabilityStatus,
            'stock_quantity' => $item->stockQuantity,
            'rating_value' => $item->ratingValue,
            'rating_count' => $item->ratingCount,
            'raw_payload' => $item->rawPayload,
        ];
    }

    /**
     * ExternalProductData is readonly, so rehydration always goes through the
     * constructor with named arguments.
     *
     * @param array<string, mixed> $row
     */
    public function hydrateItem(array $row): ExternalProductData
    {
        return new ExternalProductData(
            providerCode: (string) ($row['provider_code'] ?? ''),
            externalProductId: isset($row['external_product_id']) ? (string) $row['external_product_id'] : null,
            externalOfferId: isset($row['external_offer_id']) ? (string) $row['external_offer_id'] : null,
            title: (string) ($row['title'] ?? ''),
            brand: isset($row['brand']) ? (string) $row['brand'] : null,
            description: isset($row['description']) ? (string) $row['description'] : null,
            priceAmount: isset($row['price_amount']) ? (int) $row['price_amount'] : null,
            oldPriceAmount: isset($row['old_price_amount']) ? (int) $row['old_price_amount'] : null,
            currency: (string) ($row['currency'] ?? 'RUB'),
            categoryExternalId: isset($row['category_external_id']) ? (string) $row['category_external_id'] : null,
            categoryName: isset($row['category_name']) ? (string) $row['category_name'] : null,
            imageUrls: is_array($row['image_urls'] ?? null) ? $row['image_urls'] : [],
            productUrl: isset($row['product_url']) ? (string) $row['product_url'] : null,
            availabilityStatus: isset($row['availability_status']) ? (string) $row['availability_status'] : null,
            stockQuantity: isset($row['stock_quantity']) ? (int) $row['stock_quantity'] : null,
            ratingValue: isset($row['rating_value']) ? (float) $row['rating_value'] : null,
            ratingCount: isset($row['rating_count']) ? (int) $row['rating_count'] : null,
            rawPayload: is_array($row['raw_payload'] ?? null) ? $row['raw_payload'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResult(ProductSearchResult $result): array
    {
        return [
            'items' => array_map(
                fn (ExternalProductData $item): array => $this->serializeItem($item),
                $result->items
            ),
            'total' => $result->total,
            'next_cursor' => $result->nextCursor,
            'provider_meta' => $result->providerMeta,
            'cached_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateResult(array $payload): ProductSearchResult
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $meta = is_array($payload['provider_meta'] ?? null) ? $payload['provider_meta'] : [];
        $meta['cache'] = [
            'hit' => true,
            'cached_at' => $payload['cached_at'] ?? null,
        ];

        return new ProductSearchResult(
            items: array_map(
                fn (array $row): ExternalProductData => $this->hydrateItem($row),
                array_values(array_filter($items, 'is_array'))
            ),
            total: (int) ($payload['total'] ?? 0),
            nextCursor: $payload['next_cursor'] ?? null,
            providerMeta: $meta,
        );
    }

    private function mirrorToDatabase(
        string $fingerprint,
        ProductSearchQuery $query,
        ProductSearchResult $result,
        int $ttlSeconds,
    ): void {
        try {
            DB::transaction(function () use ($fingerprint, $query, $result, $ttlSeconds): void {
                $cache = SearchCache::query()->updateOrCreate(
                    ['cache_key' => $fingerprint],
                    [
                        'query_text' => mb_substr($query->text, 0, 512),
                        'filters' => $query->filters->toArray(),
                        'sort' => $query->sort->field->value . '_' . $query->sort->direction,
                        'providers' => $query->providerCodes,
                        'result_count' => $result->total,
                        'expires_at' => CarbonImmutable::now()->addSeconds($ttlSeconds),
                    ]
                );

                // The (search_cache_id, rank) unique index makes a full rewrite
                // the simplest correct way to refresh a re-run query.
                $cache->items()->delete();

                $rows = [];
                $now = CarbonImmutable::now();

                foreach ($result->items as $rank => $item) {
                    $rows[] = [
                        'search_cache_id' => $cache->id,
                        'provider_product_id' => null,
                        'provider_code' => $item->providerCode,
                        'external_product_id' => $item->externalProductId,
                        'rank' => $rank + 1,
                        'score' => null,
                        'snapshot' => json_encode($this->serializeItem($item), JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    SearchCacheItem::query()->insert($rows);
                }
            });
        } catch (Throwable $e) {
            // Redis already holds the result — the mirror is observability only.
            Log::warning('Search cache DB mirror failed.', [
                'cache_key' => $fingerprint,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
