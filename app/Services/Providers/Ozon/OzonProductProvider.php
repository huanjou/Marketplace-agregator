<?php

declare(strict_types=1);

namespace App\Services\Providers\Ozon;

use App\Contracts\ProductProviderInterface;
use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Marketplace\ProviderCapabilityData;
use App\DTO\Marketplace\ProviderHealthData;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Enums\Availability;
use App\Enums\ProviderCode;
use App\Enums\SearchSortField;
use App\Exceptions\ProviderAuthenticationException;
use App\Http\Clients\OzonSellerClient;
use App\Services\Providers\Support\ProviderResultPostProcessor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ozon Seller API provider.
 *
 * The seller API is a catalogue API, not a search engine: `/v3/product/list`
 * returns ids for the seller's own products with no free-text query, and
 * `/v2/product/info/list` enriches those ids with names, prices and images.
 * Text matching, rating and brand filtering therefore happen locally, after
 * the fetch (see ProviderResultPostProcessor).
 *
 * READ-ONLY: only the two listing/info endpoints above are ever called. No
 * endpoint that creates, updates, prices, blocks or archives seller data is
 * reachable from this class.
 */
class OzonProductProvider implements ProductProviderInterface
{
    private const PRODUCT_LIST_ENDPOINT = '/v3/product/list';

    private const PRODUCT_INFO_ENDPOINT = '/v2/product/info/list';

    private const MAX_RESULTS_PER_PAGE = 100;

    /** Hard ceiling on ids pulled per search, protecting latency and quota. */
    private const MAX_FETCH_LIMIT = 500;

    /** Ids per `/v2/product/info/list` call. */
    private const INFO_BATCH_SIZE = 100;

    private const HEALTH_TIMEOUT_MS = 3000;

    public function __construct(
        private readonly OzonSellerClient $client,
        private readonly OzonRateLimitPolicy $rateLimit,
        private readonly OzonProductMapper $mapper,
        private readonly ProviderResultPostProcessor $postProcessor,
    ) {}

    public function code(): string
    {
        return ProviderCode::Ozon->value;
    }

    public function displayName(): string
    {
        return (string) config('marketplace.providers.ozon.display_name', 'Ozon');
    }

    public function capabilities(): ProviderCapabilityData
    {
        return new ProviderCapabilityData(
            supportedFilters: [
                'price_range',
                'category',
                'brand',
                'availability',
            ],
            supportedSorts: [
                SearchSortField::PriceAsc->value,
                SearchSortField::PriceDesc->value,
                SearchSortField::Relevance->value,
            ],
            supportsPagination: true,
            maxResultsPerPage: self::MAX_RESULTS_PER_PAGE,
            supportsRealtimeSearch: true,
        );
    }

    /**
     * Missing credentials disable the provider instead of failing searches:
     * the aggregator simply skips it.
     */
    public function isEnabled(): bool
    {
        return (bool) config('marketplace.providers.ozon.enabled', false)
            && $this->client->isConfigured();
    }

    public function search(ProductSearchQuery $query): ProductSearchResult
    {
        if (! $this->isEnabled()) {
            return $this->emptyResult('disabled');
        }

        $query = $query->normalized();

        return $this->rateLimit->attempt(fn (): ProductSearchResult => $this->performSearch($query));
    }

    public function fetchByExternalId(string $externalId): ?ExternalProductData
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return $this->rateLimit->attempt(function () use ($externalId): ?ExternalProductData {
            $response = $this->client->postJson(
                self::PRODUCT_INFO_ENDPOINT,
                $this->infoPayloadFor($externalId),
                (int) config('marketplace.search.default_timeout_ms', 5000),
            );

            $row = $this->infoRows($response)[0] ?? null;

            return is_array($row) ? $this->mapper->map($row) : null;
        });
    }

    public function healthCheck(): ProviderHealthData
    {
        if (! $this->client->isConfigured()) {
            return $this->health('down', null, 'not_configured');
        }

        $startedAt = microtime(true);

        try {
            $this->client->postJson(
                self::PRODUCT_LIST_ENDPOINT,
                ['filter' => ['visibility' => 'ALL'], 'limit' => 1],
                self::HEALTH_TIMEOUT_MS,
            );

            return $this->health('healthy', $this->elapsedMs($startedAt));
        } catch (ProviderAuthenticationException) {
            return $this->health('down', $this->elapsedMs($startedAt), 'auth_failed');
        } catch (Throwable $e) {
            return $this->health(
                'degraded',
                $this->elapsedMs($startedAt),
                mb_substr($e->getMessage(), 0, 500),
            );
        }
    }

    private function performSearch(ProductSearchQuery $query): ProductSearchResult
    {
        $startedAt = microtime(true);
        $fetchLimit = $this->fetchLimit($query);

        // The two calls below are strictly dependent: ids first, details second.
        // Pooling them with Http::pool() would therefore buy nothing here. The
        // parallelism worth having is one level up — several marketplaces
        // queried at once — and one level down, where the info batches of a very
        // large page could be pooled. Both are left as future work so error
        // translation stays in a single place (the client).
        $list = $this->client->postJson(self::PRODUCT_LIST_ENDPOINT, [
            'filter' => $this->listFilter($query),
            'limit' => $fetchLimit,
        ], $query->timeoutMs);

        $listResult = is_array($list['result'] ?? null) ? $list['result'] : [];
        $listItems = is_array($listResult['items'] ?? null) ? $listResult['items'] : [];
        $nextCursor = $this->nonEmptyString($listResult['last_id'] ?? null);
        $catalogueTotal = is_numeric($listResult['total'] ?? null) ? (int) $listResult['total'] : null;

        if ($listItems === []) {
            return $this->emptyResult('succeeded', [
                'took_ms' => $this->elapsedMs($startedAt),
                'returned' => 0,
                'fetched' => 0,
                'catalogue_total' => $catalogueTotal,
            ]);
        }

        $items = $this->mapRows($this->fetchDetails($listItems, $query->timeoutMs));

        // The window covering the requested page is returned whole: slicing the
        // exact page is ResultAggregator's job, since it has to do so across all
        // providers at once (see its "pre-pagination items" contract).
        $matched = $this->postProcessor->sort(
            $this->postProcessor->filter($items, $query),
            $query
        );

        return new ProductSearchResult(
            items: $matched,
            total: count($matched),
            nextCursor: $nextCursor,
            providerMeta: [
                $this->code() => [
                    'status' => 'succeeded',
                    'took_ms' => $this->elapsedMs($startedAt),
                    'returned' => count($matched),
                    'fetched' => count($items),
                    'catalogue_total' => $catalogueTotal,
                ],
            ],
        );
    }

    /**
     * Enrich the id rows from `/v3/product/list` with `/v2/product/info/list`
     * details, keeping the stock/archive flags that only the list call carries.
     *
     * @param array<int, mixed> $listItems
     * @return array<int, array<string, mixed>>
     */
    private function fetchDetails(array $listItems, int $timeoutMs): array
    {
        $listRowsById = [];

        foreach ($listItems as $listItem) {
            if (! is_array($listItem)) {
                continue;
            }

            $productId = $listItem['product_id'] ?? null;

            if (! is_numeric($productId)) {
                continue;
            }

            $listRowsById[(string) $productId] = $this->withStockFlag($listItem);
        }

        if ($listRowsById === []) {
            return [];
        }

        $rows = [];

        foreach (array_chunk(array_keys($listRowsById), self::INFO_BATCH_SIZE) as $batch) {
            $response = $this->client->postJson(self::PRODUCT_INFO_ENDPOINT, [
                'product_id' => array_map('intval', $batch),
            ], $timeoutMs);

            foreach ($this->infoRows($response) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $productId = (string) ($row['product_id'] ?? $row['id'] ?? '');
                $rows[] = array_merge($listRowsById[$productId] ?? [], $row);
                unset($listRowsById[$productId]);
            }
        }

        // Products the info call did not return still carry usable list data.
        foreach ($listRowsById as $orphan) {
            $rows[] = $orphan;
        }

        return $rows;
    }

    /**
     * `/v3/product/list` reports stock per scheme; the mapper only needs to
     * know whether anything is sellable at all.
     *
     * @param array<string, mixed> $listItem
     * @return array<string, mixed>
     */
    private function withStockFlag(array $listItem): array
    {
        $fbo = $listItem['has_fbo_stocks'] ?? null;
        $fbs = $listItem['has_fbs_stocks'] ?? null;

        if (is_bool($fbo) || is_bool($fbs)) {
            $listItem['has_stocks'] = ($fbo === true) || ($fbs === true);
        }

        return $listItem;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return ExternalProductData[]
     */
    private function mapRows(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            try {
                $items[] = $this->mapper->map($row);
            } catch (Throwable $e) {
                Log::warning('Skipped an unmappable Ozon product row.', [
                    'provider_code' => $this->code(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'product_id' => $row['product_id'] ?? null,
                ]);
            }
        }

        return $items;
    }

    /**
     * The list endpoint only filters by visibility, offer id and product id, so
     * availability is the single query filter that can be pushed down.
     *
     * Visibility values are an assumption pending a live smoke test; an unknown
     * value would make Ozon reject the request, hence the conservative default.
     *
     * @return array<string, mixed>
     */
    private function listFilter(ProductSearchQuery $query): array
    {
        $visibility = match ($query->filters->availability) {
            Availability::InStock->value => 'IN_SALE',
            Availability::OutOfStock->value => 'EMPTY_STOCK',
            Availability::Archived->value => 'ARCHIVED',
            default => 'ALL',
        };

        return ['visibility' => $visibility];
    }

    /**
     * Ozon paginates by `last_id` cursor while this application paginates by
     * page number, so one call fetches a window wide enough to cover the
     * requested page; the aggregator slices it. Deep pages are capped by
     * MAX_FETCH_LIMIT.
     */
    private function fetchLimit(ProductSearchQuery $query): int
    {
        $perPage = min($query->perPage, self::MAX_RESULTS_PER_PAGE);

        return min(self::MAX_FETCH_LIMIT, max($perPage, $perPage * $query->page));
    }

    /**
     * Numeric ids are Ozon product ids; anything else is treated as a seller
     * offer id, which the same endpoint accepts.
     *
     * @return array<string, mixed>
     */
    private function infoPayloadFor(string $externalId): array
    {
        return is_numeric($externalId)
            ? ['product_id' => [(int) $externalId]]
            : ['offer_id' => [$externalId]];
    }

    /**
     * Tolerates both `result.items[]` and a bare `items[]` envelope, and the
     * single-object `result` shape of older revisions.
     *
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    private function infoRows(array $response): array
    {
        $result = $response['result'] ?? null;

        if (is_array($result)) {
            if (is_array($result['items'] ?? null)) {
                return array_values($result['items']);
            }

            if (array_key_exists('product_id', $result) || array_key_exists('offer_id', $result)) {
                return [$result];
            }
        }

        if (is_array($response['items'] ?? null)) {
            return array_values($response['items']);
        }

        Log::warning('Unexpected Ozon product info envelope.', [
            'provider_code' => $this->code(),
            'keys' => array_keys($response),
        ]);

        return [];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function emptyResult(string $status, array $meta = []): ProductSearchResult
    {
        return new ProductSearchResult(
            items: [],
            total: 0,
            nextCursor: null,
            providerMeta: [
                $this->code() => array_merge(['status' => $status], $meta),
            ],
        );
    }

    private function health(string $status, ?int $responseTimeMs, ?string $message = null): ProviderHealthData
    {
        return new ProviderHealthData(
            providerCode: $this->code(),
            status: $status,
            responseTimeMs: $responseTimeMs,
            message: $message,
            checkedAt: now()->toDateTimeImmutable(),
        );
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
