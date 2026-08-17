<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Services\Providers\Ozon\OzonProductProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the Ozon provider over a faked Playwright service: the happy path
 * fan-in and the graceful degradation when the scraper is down. The real
 * service is never contacted.
 */
class OzonScrapeProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'marketplace.providers.ozon.enabled' => true,
            // A local quota would leak between tests through the shared store.
            'marketplace.providers.ozon.rate_limit_per_minute' => 0,
        ]);

        // isReachable() memoises its verdict for 30 seconds, so each test has
        // to start from a clean slate.
        Cache::forget('playwright:reachable');
    }

    public function test_search_maps_playwright_response_to_product_search_result(): void
    {
        Http::fake([
            'playwright:3000/scrape' => Http::response($this->fixture(), 200),
            'playwright:3000/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $provider = app(OzonProductProvider::class);

        $result = $provider->search($this->searchQuery());

        $this->assertCount(5, $result->items);
        $this->assertInstanceOf(ExternalProductData::class, $result->items[0]);
        $this->assertSame('ozon', $result->items[0]->providerCode);
        $this->assertSame(8499000, $result->items[0]->priceAmount);
        $this->assertSame('RUB', $result->items[0]->currency);

        // total comes from the site's own result count hint, not the page size.
        $this->assertSame(5643, $result->total);

        $meta = $result->providerMeta['ozon'];
        $this->assertSame('succeeded', $meta['status']);
        $this->assertSame(5, $meta['returned']);
        $this->assertSame('next_data', $meta['extraction_mode']);

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/scrape')
                && $request['provider'] === 'ozon'
                && $request['query'] === 'компьютер'
                && $request['page'] === 1;
        });
    }

    public function test_search_gracefully_handles_playwright_down(): void
    {
        Http::fake(['playwright:3000/*' => Http::response('', 503)]);

        $provider = app(OzonProductProvider::class);

        $this->assertFalse($provider->isEnabled());

        // A dead scraper must never surface as an exception to the caller.
        $result = $provider->search($this->searchQuery());

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame('skipped', $result->providerMeta['ozon']['status']);
        $this->assertSame('disabled_or_unreachable', $result->providerMeta['ozon']['skipped']);

        Cache::forget('playwright:reachable');
        $health = $provider->healthCheck();

        $this->assertFalse($health->isHealthy());
        $this->assertSame('down', $health->status);
    }

    public function test_health_check_flags_an_empty_scrape_as_degraded(): void
    {
        Http::fake([
            'playwright:3000/health' => Http::response(['status' => 'ok'], 200),
            'playwright:3000/scrape' => Http::response(['items' => [], 'meta' => ['provider' => 'ozon']], 200),
        ]);

        $health = app(OzonProductProvider::class)->healthCheck();

        $this->assertFalse($health->isHealthy());
        $this->assertSame('degraded', $health->status);
        $this->assertStringContainsString('antibot', (string) $health->message);
    }

    public function test_fetch_by_external_id_is_not_supported_on_the_scraping_path(): void
    {
        Http::fake();

        $this->assertNull(app(OzonProductProvider::class)->fetchByExternalId('1512345678'));

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/ozon/scrape_response.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    private function searchQuery(): ProductSearchQuery
    {
        return new ProductSearchQuery(
            text: 'компьютер',
            filters: new ProductSearchFilters(),
            sort: new ProductSort(),
            page: 1,
            perPage: 20,
            providerCodes: ['ozon'],
        );
    }
}
