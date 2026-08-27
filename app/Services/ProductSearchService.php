<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProductProviderInterface;
use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Models\Provider;
use App\Models\SyncLog;
use App\Services\Ai\PerplexitySearchUrlService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entry point for every product search in the application.
 *
 * Pipeline: normalize query -> cache lookup -> AI search-URL hints ->
 * provider fan-out -> merge -> de-duplicate/sort/paginate -> best-effort
 * persist -> cache -> log.
 */
class ProductSearchService
{
    /**
     * provider_code => providers.id, memoised for sync-log writes.
     *
     * @var array<string, int|null>
     */
    private array $providerIds = [];

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly SearchCacheService $cache,
        private readonly ResultAggregator $aggregator,
        private readonly ProductResultNormalizer $normalizer,
        private readonly PerplexitySearchUrlService $searchUrls,
    ) {}

    public function search(ProductSearchQuery $query): ProductSearchResult
    {
        $query = $query->normalized();
        $requestedPage = $query->page;

        // Scrapers always harvest the first SERP surface; the requested page
        // is sliced out of the cached full set at the end. Passing a deeper
        // page down would both re-run the scrapers and poison the shared
        // cache with a partial set.
        $query = new ProductSearchQuery(
            text: $query->text,
            filters: $query->filters,
            sort: $query->sort,
            page: 1,
            perPage: $query->perPage,
            providerCodes: $query->providerCodes,
            timeoutMs: $query->timeoutMs,
            searchUrls: $query->searchUrls,
        );

        $startedAt = CarbonImmutable::now();
        $startedAtMs = microtime(true);

        // Кеш временно отключён, чтобы каждый запрос вызывал реальный поиск у провайдеров.
        // $cached = $this->cache->get($query);
        // if ($cached !== null) {
        //     ...
        //     return $cached->forPage($requestedPage, $query->perPage);
        // }

        $providers = $this->registry
            ->matching($query->providerCodes)
            ->filter(static fn (ProductProviderInterface $provider): bool => $provider->isEnabled());

        if ($providers->isEmpty()) {
            Log::warning('Product search ran with no enabled providers.', [
                'requested_providers' => $query->providerCodes,
            ]);

            return new ProductSearchResult(
                providerMeta: ['warning' => 'no_enabled_providers']
            );
        }

        $query = $this->enrichWithAiSearchUrls($query, $providers);
        $query = $this->applyAiPriceBounds($query);

        $fanOut = $this->fanOut($providers, $query);

        $result = $this->aggregator->aggregate($fanOut['items'], $query);

        // Persisting offers is a side effect of searching: it must never be able
        // to fail the request the user is waiting on.
        try {
            $this->normalizer->persist($fanOut['items']);
        } catch (Throwable $e) {
            Log::error('Failed to persist provider offers after search.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'items' => count($fanOut['items']),
            ]);
        }

        $result = new ProductSearchResult(
            items: $result->items,
            total: $result->total,
            nextCursor: $result->nextCursor,
            providerMeta: array_merge($result->providerMeta, $fanOut['meta'], [
                'cache' => ['hit' => false],
                PerplexitySearchUrlService::META_KEY => $query->searchUrls,
                // The set is cached under THESE filters (AI price bounds
                // included); callers need them to hit the same fingerprint
                // when they request another page.
                'effective_filters' => $query->filters->toArray(),
            ]),
        );

        // Cache the whole match set under a page-independent key so that
        // subsequent page flips are served from memory.
        $this->cache->put(
            $query,
            $result,
            (int) config('marketplace.search.cache_ttl_seconds', 300)
        );

        $paged = $result->forPage($requestedPage, $query->perPage);

        $this->logSearch($query, $paged, $fanOut, $startedAt, $startedAtMs, $requestedPage);

        return $paged;
    }

    /**
     * Health snapshot of every enabled provider, keyed by provider code.
     *
     * @return array<string, \App\DTO\Marketplace\ProviderHealthData>
     */
    public function health(): array
    {
        return $this->registry->enabled()
            ->map(static fn (ProductProviderInterface $provider) => $provider->healthCheck())
            ->all();
    }

    /**
     * Ask Perplexity for ready-to-open marketplace search URLs (query text
     * and price filters baked in) and attach them to the query, restricted to
     * the providers that actually run in this search. Callers that already
     * supplied their own URLs (tests, jobs) are never overridden.
     * The call is best-effort: without an API key or on any failure the
     * providers simply keep their plain-text search path.
     *
     * @param Collection<string, ProductProviderInterface> $providers
     */
    private function enrichWithAiSearchUrls(ProductSearchQuery $query, Collection $providers): ProductSearchQuery
    {
        if ($query->searchUrls !== []) {
            return $query;
        }

        try {
            $urls = $this->searchUrls->urlsFor($query->text);
        } catch (Throwable $e) {
            Log::warning('AI search URL resolution failed, continuing without it.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'text' => $query->text,
            ]);

            return $query;
        }

        if ($urls === []) {
            return $query;
        }

        return new ProductSearchQuery(
            text: $query->text,
            filters: $query->filters,
            sort: $query->sort,
            page: $query->page,
            perPage: $query->perPage,
            providerCodes: $query->providerCodes,
            timeoutMs: $query->timeoutMs,
            searchUrls: array_intersect_key($urls, $providers->all()),
        );
    }

    /**
     * Fold the budget implied by the AI-built URLs into the query filters.
     *
     * Marketplaces often ignore price params on a headless SERP visit, so the
     * bounds end up enforced again in ResultAggregator — this merge is what
     * hands them over, and it also folds them into the cache fingerprint.
     * Explicit user filters always win.
     */
    private function applyAiPriceBounds(ProductSearchQuery $query): ProductSearchQuery
    {
        if ($query->searchUrls === []) {
            return $query;
        }

        $filters = $query->filters;

        if ($filters->minPriceAmount !== null && $filters->maxPriceAmount !== null) {
            return $query;
        }

        $bounds = PerplexitySearchUrlService::priceBounds($query->searchUrls);

        $min = $filters->minPriceAmount ?? $bounds['min'];
        $max = $filters->maxPriceAmount ?? $bounds['max'];

        if ($min === $filters->minPriceAmount && $max === $filters->maxPriceAmount) {
            return $query;
        }

        return new ProductSearchQuery(
            text: $query->text,
            filters: new ProductSearchFilters(
                minPriceAmount: $min,
                maxPriceAmount: $max,
                currency: $filters->currency,
                categoryId: $filters->categoryId,
                externalCategoryIds: $filters->externalCategoryIds,
                brands: $filters->brands,
                minRating: $filters->minRating,
                availability: $filters->availability,
                attributes: $filters->attributes,
            ),
            sort: $query->sort,
            page: $query->page,
            perPage: $query->perPage,
            providerCodes: $query->providerCodes,
            timeoutMs: $query->timeoutMs,
            searchUrls: $query->searchUrls,
        );
    }

    /**
     * Query every provider and collect their offers.
     *
     * Providers are invoked sequentially here on purpose. Real concurrency
     * lives INSIDE each provider, which uses Http::pool() to issue its own
     * batch of marketplace calls in parallel; at this level each provider is
     * merely isolated (one failure never sinks the others) and its duration is
     * recorded. Moving the fan-out itself to a process/async pool (e.g. queued
     * jobs collected via a batch, or Octane concurrency) is a straightforward
     * future change because nothing below depends on call ordering.
     *
     * @param Collection<string, ProductProviderInterface> $providers
     * @return array{items: ExternalProductData[], meta: array<string, mixed>, succeeded: string[], failed: string[]}
     */
    private function fanOut(Collection $providers, ProductSearchQuery $query): array
    {
        $items = [];
        $meta = [];
        $succeeded = [];
        $failed = [];

        foreach ($providers as $code => $provider) {
            $providerStartedAt = CarbonImmutable::now();
            $providerStartedAtMs = microtime(true);

            try {
                $providerResult = $provider->search($query);

                $durationMs = $this->elapsedMs($providerStartedAtMs);

                $items = array_merge($items, $providerResult->items);
                $succeeded[] = $code;

                $meta[$code] = array_merge(
                    is_array($providerResult->providerMeta[$code] ?? null)
                        ? $providerResult->providerMeta[$code]
                        : [],
                    [
                        'status' => 'succeeded',
                        'returned' => count($providerResult->items),
                        'total' => $providerResult->total,
                        'duration_ms' => $durationMs,
                    ]
                );

                // A technically successful run that brings zero items is almost
                // always an anti-bot block (challenge/captcha page) and must
                // not be silently swallowed by the UI.
                if ($providerResult->items === []) {
                    $meta[$code]['warning'] = 'empty_result';
                    $meta[$code]['error'] = 'не вернул товары (возможна антибот-защита)';

                    Log::warning('Provider search succeeded but returned no items.', [
                        'provider_code' => $code,
                        'text' => $query->text,
                        'duration_ms' => $durationMs,
                    ]);
                }

                if ($durationMs > $query->timeoutMs) {
                    Log::warning('Provider search exceeded its time budget.', [
                        'provider_code' => $code,
                        'duration_ms' => $durationMs,
                        'timeout_ms' => $query->timeoutMs,
                    ]);
                }
            } catch (Throwable $e) {
                $durationMs = $this->elapsedMs($providerStartedAtMs);
                $failed[] = $code;

                $meta[$code] = [
                    'status' => 'failed',
                    'returned' => 0,
                    'duration_ms' => $durationMs,
                    'error' => $e->getMessage(),
                ];

                $this->logProviderFailure($code, $query, $e, $providerStartedAt, $durationMs);
            }
        }

        return [
            'items' => $items,
            'meta' => $meta,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    /**
     * @param array{items: ExternalProductData[], meta: array<string, mixed>, succeeded: string[], failed: string[]} $fanOut
     */
    private function logSearch(
        ProductSearchQuery $query,
        ProductSearchResult $result,
        array $fanOut,
        CarbonImmutable $startedAt,
        float $startedAtMs,
        int $requestedPage,
    ): void {
        $this->writeSyncLog([
            'provider_code' => null,
            'operation' => 'search',
            'status' => $fanOut['failed'] === [] ? 'succeeded' : 'partial',
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => $this->elapsedMs($startedAtMs),
            'request_summary' => [
                'text' => $query->text,
                'filters' => $query->filters->toArray(),
                'sort' => $query->sort->field->value . '_' . $query->sort->direction,
                'page' => $requestedPage,
                'per_page' => $query->perPage,
                'providers' => array_keys($fanOut['meta']),
            ],
            'response_summary' => [
                'total' => $result->total,
                'returned' => count($result->items),
                'providers_succeeded' => $fanOut['succeeded'],
                'providers_failed' => $fanOut['failed'],
                'per_provider' => $fanOut['meta'],
            ],
        ]);
    }

    private function logProviderFailure(
        string $code,
        ProductSearchQuery $query,
        Throwable $e,
        CarbonImmutable $startedAt,
        int $durationMs,
    ): void {
        Log::error('Provider search failed, continuing with partial results.', [
            'provider_code' => $code,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $this->writeSyncLog([
            'provider_id' => $this->providerId($code),
            'provider_code' => $code,
            'operation' => 'search',
            'status' => 'failed',
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => $durationMs,
            'request_summary' => [
                'text' => $query->text,
                'filters' => $query->filters->toArray(),
                'page' => $query->page,
                'per_page' => $query->perPage,
                'timeout_ms' => $query->timeoutMs,
            ],
            'error_class' => $e::class,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }

    /**
     * Audit trail writes are never allowed to break a search.
     *
     * @param array<string, mixed> $attributes
     */
    private function writeSyncLog(array $attributes): void
    {
        try {
            SyncLog::query()->create($attributes);
        } catch (Throwable $e) {
            Log::warning('Failed to write search sync log.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function providerId(string $code): ?int
    {
        if (array_key_exists($code, $this->providerIds)) {
            return $this->providerIds[$code];
        }

        try {
            $id = Provider::query()->where('code', $code)->value('id');
        } catch (Throwable) {
            $id = null;
        }

        return $this->providerIds[$code] = $id !== null ? (int) $id : null;
    }

    private function elapsedMs(float $startedAtMs): int
    {
        return (int) round((microtime(true) - $startedAtMs) * 1000);
    }
}
