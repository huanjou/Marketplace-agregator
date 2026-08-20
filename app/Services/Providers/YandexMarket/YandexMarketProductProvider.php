<?php

declare(strict_types=1);

namespace App\Services\Providers\YandexMarket;

use App\Contracts\ProductProviderInterface;
use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Marketplace\ProviderCapabilityData;
use App\DTO\Marketplace\ProviderHealthData;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Enums\ProviderCode;
use App\Enums\SearchSortField;
use App\Exceptions\ProviderRateLimitException;
use App\Http\Clients\PlaywrightScraperClient;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Yandex Market provider backed by the Playwright scraping service.
 *
 * Searches the public market.yandex.ru search page via the in-cluster
 * Playwright service. No API credentials are required — the scraper
 * extracts structured data from the rendered page.
 *
 * READ-ONLY: only search scraping is performed, no mutations.
 */
class YandexMarketProductProvider implements ProductProviderInterface
{
    private const MAX_RESULTS_PER_PAGE = 48;

    private const SCRAPE_TIMEOUT_MS = 8000;

    public function __construct(
        private readonly PlaywrightScraperClient $scraper,
        private readonly YandexMarketRateLimitPolicy $rateLimit,
        private readonly YandexMarketProductMapper $mapper,
    ) {}

    public function code(): string
    {
        return ProviderCode::YandexMarket->value;
    }

    public function displayName(): string
    {
        return 'Яндекс Маркет';
    }

    public function capabilities(): ProviderCapabilityData
    {
        return new ProviderCapabilityData(
            supportedFilters: [
                'text_search',
                'price_range',
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

    public function isEnabled(): bool
    {
        try {
            $dbEnabled = \App\Models\Provider::query()->where('code', $this->code())->value('enabled');
        } catch (\Throwable $e) {
            $dbEnabled = null;
        }

        $enabled = $dbEnabled ?? (bool) config('marketplace.providers.yandex_market.enabled', false);

        return $enabled && $this->scraper->isReachable();
    }

    public function search(ProductSearchQuery $query): ProductSearchResult
    {
        $startedAt = microtime(true);
        $syncLog = $this->createSyncLog($query);

        try {
            $this->rateLimit->attempt(function () {});
        } catch (ProviderRateLimitException $e) {
            $this->failSyncLog($syncLog, $e);
            throw $e;
        }

        try {
            $response = $this->scraper->scrape(
                'yandex_market',
                $query->text,
                $query->page,
                self::SCRAPE_TIMEOUT_MS,
            );

            $items = $this->mapper->mapMany($response->items, $query);

            $result = new ProductSearchResult(
                items: $items,
                total: (int) ($response->meta['total_hint'] ?? count($items)),
                providerMeta: [
                    'yandex_market' => array_merge($response->meta, [
                        'returned' => count($items),
                        'took_ms' => $this->elapsedMs($startedAt),
                    ]),
                ],
            );

            $this->succeedSyncLog($syncLog, $result, $startedAt);

            return $result;
        } catch (Throwable $e) {
            $this->failSyncLog($syncLog, $e);
            throw $e;
        }
    }

    public function fetchByExternalId(string $externalId): ?ExternalProductData
    {
        return null;
    }

    public function healthCheck(): ProviderHealthData
    {
        $startedAt = microtime(true);

        if (! $this->scraper->isReachable()) {
            return $this->health('down', $this->elapsedMs($startedAt), 'playwright_unreachable');
        }

        try {
            $this->scraper->scrape('yandex_market', 'test', 1, 5000);

            return $this->health('healthy', $this->elapsedMs($startedAt));
        } catch (Throwable $e) {
            return $this->health(
                'degraded',
                $this->elapsedMs($startedAt),
                mb_substr($e->getMessage(), 0, 500),
            );
        }
    }

    private function createSyncLog(ProductSearchQuery $query): SyncLog
    {
        return SyncLog::create([
            'provider_code' => $this->code(),
            'operation' => 'search',
            'status' => 'running',
            'started_at' => now(),
            'request_summary' => [
                'text' => $query->text,
                'page' => $query->page,
                'per_page' => $query->perPage,
            ],
        ]);
    }

    private function succeedSyncLog(SyncLog $syncLog, ProductSearchResult $result, float $startedAt): void
    {
        $syncLog->markCompleted('succeeded', [
            'total' => $result->total,
            'returned' => count($result->items),
            'took_ms' => $this->elapsedMs($startedAt),
        ]);
    }

    private function failSyncLog(SyncLog $syncLog, Throwable $e): void
    {
        $syncLog->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_class' => $e::class,
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
        ]);
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
