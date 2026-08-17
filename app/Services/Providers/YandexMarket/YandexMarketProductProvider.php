<?php

declare(strict_types=1);

namespace App\Services\Providers\YandexMarket;

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
use App\Http\Clients\YandexMarketClient;
use App\Services\Providers\Support\ProviderResultPostProcessor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Yandex Market Partner API provider.
 *
 * `POST /v2/businesses/{businessId}/offer-mappings` lists the business's own
 * offers together with their Market SKU mapping. Like the Ozon seller API it
 * has no free-text query, so text matching and rating filters are applied
 * locally after the fetch (see ProviderResultPostProcessor). Brand and category
 * filters are pushed down to the request body.
 *
 * READ-ONLY: only the offer-mappings listing endpoint is ever called. No price,
 * stock, order or campaign mutation is reachable from this class.
 */
class YandexMarketProductProvider implements ProductProviderInterface
{
    private const MAX_RESULTS_PER_PAGE = 100;

    /** The Partner API caps `limit` on offer-mappings at 200. */
    private const MAX_FETCH_LIMIT = 200;

    private const HEALTH_TIMEOUT_MS = 3000;

    public function __construct(
        private readonly YandexMarketClient $client,
        private readonly YandexMarketRateLimitPolicy $rateLimit,
        private readonly YandexMarketProductMapper $mapper,
        private readonly ProviderResultPostProcessor $postProcessor,
    ) {}

    public function code(): string
    {
        return ProviderCode::YandexMarket->value;
    }

    public function displayName(): string
    {
        return (string) config('marketplace.providers.yandex_market.display_name', 'Yandex Market');
    }

    public function capabilities(): ProviderCapabilityData
    {
        return new ProviderCapabilityData(
            supportedFilters: [
                'price_range',
                'category',
                'brand',
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
        return (bool) config('marketplace.providers.yandex_market.enabled', false)
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
                $this->offerMappingsEndpoint(),
                ['offerIds' => [$externalId]],
                (int) config('marketplace.search.default_timeout_ms', 5000),
                ['limit' => 1],
            );

            $row = $this->offerMappingRows($response)[0] ?? null;

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
                $this->offerMappingsEndpoint(),
                [],
                self::HEALTH_TIMEOUT_MS,
                ['limit' => 1],
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

        // A single call covering the requested page: the Partner API paginates
        // with an opaque `page_token` that our page-numbered query cannot
        // address, so a wide enough window is fetched and the aggregator slices
        // it. Concurrency across marketplaces belongs one level up, in the
        // search service fan-out.
        $response = $this->client->postJson(
            $this->offerMappingsEndpoint(),
            $this->offerFilters($query),
            $query->timeoutMs,
            ['limit' => $this->fetchLimit($query)],
        );

        $rows = $this->offerMappingRows($response);
        $nextCursor = $this->nextPageToken($response);

        if ($rows === []) {
            return $this->emptyResult('succeeded', [
                'took_ms' => $this->elapsedMs($startedAt),
                'returned' => 0,
                'fetched' => 0,
            ]);
        }

        $items = $this->mapRows($rows);

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
                ],
            ],
        );
    }

    /**
     * @param array<int, mixed> $rows
     * @return ExternalProductData[]
     */
    private function mapRows(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            try {
                $items[] = $this->mapper->map($row);
            } catch (Throwable $e) {
                Log::warning('Skipped an unmappable Yandex Market offer mapping.', [
                    'provider_code' => $this->code(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $items;
    }

    /**
     * Body filters supported by offer-mappings. Field names are an assumption
     * pending a live smoke test, so only filters the caller actually set are
     * sent — an empty body lists the whole catalogue page.
     *
     * @return array<string, mixed>
     */
    private function offerFilters(ProductSearchQuery $query): array
    {
        $filters = $query->filters;
        $payload = [];

        if ($filters->brands !== []) {
            $payload['vendorNames'] = array_values(array_map('strval', $filters->brands));
        }

        $categoryIds = array_values(array_filter(
            array_map('intval', array_filter($filters->externalCategoryIds, 'is_numeric')),
            static fn (int $id): bool => $id > 0
        ));

        if ($categoryIds !== []) {
            $payload['categoryIds'] = $categoryIds;
        }

        if ($filters->availability === Availability::Archived->value) {
            $payload['archived'] = true;
        }

        return $payload;
    }

    private function offerMappingsEndpoint(): string
    {
        return '/businesses/' . $this->client->businessId() . '/offer-mappings';
    }

    private function fetchLimit(ProductSearchQuery $query): int
    {
        $perPage = min($query->perPage, self::MAX_RESULTS_PER_PAGE);

        return min(self::MAX_FETCH_LIMIT, max($perPage, $perPage * $query->page));
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    private function offerMappingRows(array $response): array
    {
        $result = $response['result'] ?? null;

        if (is_array($result) && is_array($result['offerMappings'] ?? null)) {
            return array_values($result['offerMappings']);
        }

        if (is_array($response['offerMappings'] ?? null)) {
            return array_values($response['offerMappings']);
        }

        Log::warning('Unexpected Yandex Market offer-mappings envelope.', [
            'provider_code' => $this->code(),
            'keys' => array_keys($response),
        ]);

        return [];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function nextPageToken(array $response): ?string
    {
        $result = is_array($response['result'] ?? null) ? $response['result'] : $response;
        $paging = is_array($result['paging'] ?? null) ? $result['paging'] : [];
        $token = $paging['nextPageToken'] ?? null;

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token !== '' ? $token : null;
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

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
