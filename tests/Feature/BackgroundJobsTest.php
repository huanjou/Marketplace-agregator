<?php

namespace Tests\Feature;

use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Enums\SearchSortField;
use App\Enums\SyncStatus;
use App\Jobs\ProviderHealthCheck;
use App\Jobs\RefreshProviderProduct;
use App\Jobs\SyncProviderCatalog;
use App\Jobs\WarmProductSearchCache;
use App\Models\Provider;
use App\Models\ProviderProduct;
use App\Models\SyncLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the background job layer: every run has to leave a sync_logs trail,
 * and disabled providers must never be called.
 */
class BackgroundJobsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_catalog_sync_persists_offers_and_logs_success(): void
    {
        $before = ProviderProduct::where('provider_code', 'fake')->count();

        dispatch_sync(new SyncProviderCatalog(providerCode: 'fake', pagesPerQuery: 1, perPage: 50));

        $log = $this->latestLog('catalog_sync', 'fake');

        $this->assertSame(SyncStatus::Succeeded->value, $log->status);
        $this->assertGreaterThan(0, $log->response_summary['persisted']);
        $this->assertSame(0, $log->response_summary['failed_pages']);
        $this->assertGreaterThanOrEqual($before, ProviderProduct::where('provider_code', 'fake')->count());
        $this->assertNotNull($log->finished_at);
    }

    public function test_catalog_sync_is_idempotent(): void
    {
        dispatch_sync(new SyncProviderCatalog(providerCode: 'fake', pagesPerQuery: 1, perPage: 50));
        $after = ProviderProduct::where('provider_code', 'fake')->count();

        dispatch_sync(new SyncProviderCatalog(providerCode: 'fake', pagesPerQuery: 1, perPage: 50));

        $this->assertSame($after, ProviderProduct::where('provider_code', 'fake')->count());
    }

    public function test_catalog_sync_short_circuits_for_a_disabled_provider(): void
    {
        dispatch_sync(new SyncProviderCatalog(providerCode: 'ozon'));

        $log = $this->latestLog('catalog_sync', 'ozon');

        $this->assertSame(SyncStatus::Failed->value, $log->status);
        $this->assertSame(['reason' => 'disabled'], $log->response_summary);
    }

    public function test_refreshing_an_unknown_external_id_is_logged_as_not_found(): void
    {
        dispatch_sync(new RefreshProviderProduct(providerCode: 'fake', externalId: 'no-such-offer'));

        $log = $this->latestLog('product_refresh', 'fake');

        $this->assertSame(SyncStatus::Failed->value, $log->status);
        $this->assertSame(['reason' => 'not_found'], $log->response_summary);
    }

    public function test_health_check_records_the_verdict_on_every_provider(): void
    {
        // The scraping transports are faked down: the sweep must never hit a
        // real scraper service, and unreachable transports read as 'down'.
        Cache::flush();
        Http::fake(['*' => Http::response('', 503)]);

        dispatch_sync(new ProviderHealthCheck());

        $this->assertSame('healthy', Provider::where('code', 'fake')->value('last_health_status'));
        $this->assertNotNull(Provider::where('code', 'fake')->value('last_checked_at'));

        $this->assertSame(SyncStatus::Succeeded->value, $this->latestLog('health_check', 'fake')->status);

        // With the scraper transports down, the scraping providers report 'down'.
        $this->assertSame('down', Provider::where('code', 'ozon')->value('last_health_status'));
    }

    public function test_the_warm_cache_job_survives_serialization(): void
    {
        $job = WarmProductSearchCache::forQuery(new ProductSearchQuery(
            text: 'laptop',
            filters: new ProductSearchFilters(minPriceAmount: 1000, currency: 'RUB'),
            sort: new ProductSort(field: SearchSortField::PriceAsc, direction: 'asc'),
            page: 2,
            perPage: 10,
            providerCodes: ['fake'],
        ));

        $restored = unserialize(serialize($job));

        $this->assertSame('laptop', $restored->querySnapshot['text']);
        $this->assertSame('price_asc', $restored->querySnapshot['sort_field']);
        $this->assertSame(1000, $restored->querySnapshot['filters']['min_price_amount']);

        // Rebuilding the query and running the search must not raise.
        dispatch_sync($restored);
        dispatch_sync(new WarmProductSearchCache([]));
    }

    private function latestLog(string $operation, string $providerCode): SyncLog
    {
        return SyncLog::where('operation', $operation)
            ->where('provider_code', $providerCode)
            ->latest('id')
            ->firstOrFail();
    }
}
