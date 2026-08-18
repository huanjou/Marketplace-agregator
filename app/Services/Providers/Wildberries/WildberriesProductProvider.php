<?php

declare(strict_types=1);

namespace App\Services\Providers\Wildberries;

use App\Contracts\ProductProviderInterface;
use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Marketplace\ProviderCapabilityData;
use App\DTO\Marketplace\ProviderHealthData;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Enums\ProviderCode;
use App\Enums\SearchSortField;
use App\Exceptions\ProviderUnavailableException;
use App\Http\Clients\PlaywrightScraperClient;
use App\Models\SyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wildberries provider backed by the Playwright scraping service.
 *
 * The public search page (`https://www.wildberries.ru/search?query=...`) is
 * rendered by the in-cluster Playwright service, which extracts the product
 * cards and hands back normalised snake_case dicts. This IS a real search
 * engine: the free-text query is pushed down to Wildberries and relevance is
 * theirs, so no local text matching happens here.
 *
 * READ-ONLY: the scraper only ever performs GET navigations to public search
 * pages. Nothing in this class can create, update or price anything on
 * Wildberries.
 */
class WildberriesProductProvider implements ProductProviderInterface
{
    /**
     * One scraped search page holds up to ~100 cards after the scroll loop;
     * asking for more would need several navigations, which the scraper does
     * not do per request.
     */
    private const MAX_RESULTS_PER_PAGE = 100;

    /** Budget for a search scrape: warm-up + up to three navigation attempts. */
    private const SEARCH_TIMEOUT_MS = 20000;

    /** Health probes get a shorter budget so a slow site fails fast. */
    private const HEALTH_TIMEOUT_MS = 10000;

    /** Cheap, always-populated query used as the health canary. */
    private const HEALTH_CANARY_QUERY = 'test';

    public function __construct(
        private readonly PlaywrightScraperClient $scraper,
        private readonly WildberriesRateLimitPolicy $rateLimit,
        private readonly WildberriesProductMapper $mapper,
    ) {}

    public function code(): string
    {
        return ProviderCode::Wildberries->value;
    }

    public function displayName(): string
    {
        return (string) config('marketplace.providers.wildberries.display_name', 'Wildberries');
    }

    /**
     * Only free-text search is pushed down (to Wildberries' own search
     * engine); the sorts below are applied globally by ResultAggregator across
     * providers. Structural filters (price range, brand, availability) are NOT
     * honoured on the scraping path — the search page exposes them as URL
     * facets we do not build yet, so they are deliberately not advertised.
     */
    public function capabilities(): ProviderCapabilityData
    {
        return new ProviderCapabilityData(
            supportedFilters: [
                'text_search',
            ],
            supportedSorts: [
                SearchSortField::Relevance->value,
                SearchSortField::PriceAsc->value,
                SearchSortField::PriceDesc->value,
            ],
            supportsPagination: true,
            maxResultsPerPage: self::MAX_RESULTS_PER_PAGE,
            supportsRealtimeSearch: true,
        );
    }

    /**
     * A down Playwright service disables the provider instead of failing
     * searches: the aggregator simply skips it. The reachability verdict is
     * cached for 30 seconds inside the client, so a search burst pings the
     * service at most once.
     */
    public function isEnabled(): bool
    {
        return (bool) config('marketplace.providers.wildberries.enabled', false)
            && $this->scraper->isReachable();
    }

    public function search(ProductSearchQuery $query): ProductSearchResult
    {
        // ProductSearchService already filters on isEnabled(); this guard keeps
        // direct callers (jobs, tinker) from hitting a dead scraper.
        if (! $this->isEnabled()) {
            return $this->emptyResult('skipped', ['skipped' => 'disabled_or_unreachable']);
        }

        $query = $query->normalized();

        return $this->rateLimit->attempt(fn (): ProductSearchResult => $this->performSearch($query));
    }

    /**
     * Public search pages carry no stable, queryable product id beyond the
     * article id in the card link; reaching the product page requires a second
     * navigation per product and returns a different DOM than the search cards.
     * Refresh flows therefore treat Wildberries offers as search-only; a
     * dedicated product scraper can be added later behind this method.
     */
    public function fetchByExternalId(string $externalId): ?ExternalProductData
    {
        return null;
    }

    public function healthCheck(): ProviderHealthData
    {
        if (! $this->scraper->isReachable()) {
            return $this->health('down', null, 'Playwright service unreachable');
        }

        $startedAtMs = microtime(true);

        try {
            $response = $this->scraper->scrape(
                $this->code(),
                self::HEALTH_CANARY_QUERY,
                1,
                self::HEALTH_TIMEOUT_MS,
            );
        } catch (ProviderUnavailableException $e) {
            return $this->health('down', $this->elapsedMs($startedAtMs), mb_substr($e->getMessage(), 0, 500));
        } catch (Throwable $e) {
            return $this->health('degraded', $this->elapsedMs($startedAtMs), mb_substr($e->getMessage(), 0, 500));
        }

        // A reachable scraper that extracts nothing means the page rendered but
        // the cards did not: either an antibot wall or DOM drift.
        if ($response->items === []) {
            return $this->health(
                'degraded',
                $this->elapsedMs($startedAtMs),
                'Zero items returned — possible antibot block',
            );
        }

        return $this->health('healthy', $this->elapsedMs($startedAtMs));
    }

    private function performSearch(ProductSearchQuery $query): ProductSearchResult
    {
        $startedAt = CarbonImmutable::now();
        $startedAtMs = microtime(true);

        try {
            $response = $this->scraper->scrape(
                $this->code(),
                $query->text,
                $query->page,
                self::SEARCH_TIMEOUT_MS,
            );

            $items = $this->mapper->mapMany($response->items, $query);
            $durationMs = $this->elapsedMs($startedAtMs);

            // The whole match set of the scraped page is returned: slicing the
            // exact page is ResultAggregator's job, since it has to do so
            // across all providers at once.
            $result = new ProductSearchResult(
                items: $items,
                total: (int) ($response->meta['total_hint'] ?? count($items)),
                nextCursor: null,
                providerMeta: [
                    $this->code() => array_merge($response->meta, [
                        'status' => 'succeeded',
                        'returned' => count($items),
                        'took_ms' => $durationMs,
                    ]),
                ],
            );

            $this->writeSyncLog([
                'operation' => 'search',
                'status' => 'succeeded',
                'started_at' => $startedAt,
                'finished_at' => CarbonImmutable::now(),
                'duration_ms' => $durationMs,
                'request_summary' => $this->requestSummary($query),
                'response_summary' => [
                    'returned' => count($items),
                    'scraped' => count($response->items),
                    'total_hint' => $result->total,
                    'extraction_mode' => $response->extractionMode(),
                    'scraper_took_ms' => $response->tookMs(),
                ],
            ]);

            return $result;
        } catch (Throwable $e) {
            // ProductSearchService isolates the provider and records its own
            // failure row; this one additionally carries the scraper detail
            // (timeouts, ANTIBOT codes) that only this layer sees.
            $this->writeSyncLog([
                'operation' => 'search',
                'status' => 'failed',
                'started_at' => $startedAt,
                'finished_at' => CarbonImmutable::now(),
                'duration_ms' => $this->elapsedMs($startedAtMs),
                'request_summary' => $this->requestSummary($query),
                'error_class' => $e::class,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSummary(ProductSearchQuery $query): array
    {
        return [
            'text' => $query->text,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'timeout_ms' => self::SEARCH_TIMEOUT_MS,
            'transport' => 'playwright',
        ];
    }

    /**
     * Audit trail writes are never allowed to break a search.
     *
     * @param array<string, mixed> $attributes
     */
    private function writeSyncLog(array $attributes): void
    {
        try {
            SyncLog::query()->create(array_merge(['provider_code' => $this->code()], $attributes));
        } catch (Throwable $e) {
            Log::warning('Failed to write Wildberries search sync log.', [
                'provider_code' => $this->code(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
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

    private function elapsedMs(float $startedAtMs): int
    {
        return (int) round((microtime(true) - $startedAtMs) * 1000);
    }
}
