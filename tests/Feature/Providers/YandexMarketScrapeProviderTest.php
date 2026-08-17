<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\Http\Clients\PlaywrightScraperClient;
use App\Services\Providers\YandexMarket\YandexMarketProductProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexMarketScrapeProviderTest extends TestCase
{
    private function fixturePayload(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/yandex_market/scrape_response.json')),
            true,
        );
    }

    public function test_search_maps_playwright_response(): void
    {
        // Ensure the reachable cache doesn't interfere
        Cache::forget('playwright:reachable');

        $fixture = $this->fixturePayload();

        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'pool' => [], 'uptime_ms' => 60000], 200),
            '*/scrape' => Http::response($fixture, 200),
        ]);

        /** @var YandexMarketProductProvider $provider */
        $provider = $this->app->make(YandexMarketProductProvider::class);

        $query = new ProductSearchQuery(text: 'samsung', page: 1, perPage: 20);

        $result = $provider->search($query);

        $this->assertCount(5, $result->items);
        $this->assertInstanceOf(ExternalProductData::class, $result->items[0]);
        $this->assertSame('yandex_market', $result->items[0]->providerCode);
        $this->assertSame(2418, $result->total);
        $this->assertArrayHasKey('yandex_market', $result->providerMeta);
        $this->assertArrayHasKey('took_ms', $result->providerMeta['yandex_market']);
        $this->assertSame(5, $result->providerMeta['yandex_market']['returned']);
    }

    public function test_search_gracefully_handles_playwright_down(): void
    {
        Cache::forget('playwright:reachable');

        Http::fake([
            '*/health' => Http::response(['error' => 'service down'], 503),
            '*/scrape' => Http::response(['error' => 'service down'], 503),
        ]);

        /** @var YandexMarketProductProvider $provider */
        $provider = $this->app->make(YandexMarketProductProvider::class);

        $this->assertFalse($provider->isEnabled());
    }
}
