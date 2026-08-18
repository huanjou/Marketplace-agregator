<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\Services\Providers\Wildberries\WildberriesProductProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesScrapeProviderTest extends TestCase
{
    private function fixturePayload(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/wildberries/scrape_response.json')),
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

        /** @var WildberriesProductProvider $provider */
        $provider = $this->app->make(WildberriesProductProvider::class);

        $query = new ProductSearchQuery(text: 'подушка', page: 1, perPage: 20);

        $result = $provider->search($query);

        $this->assertCount(5, $result->items);
        $this->assertInstanceOf(ExternalProductData::class, $result->items[0]);
        $this->assertSame('wildberries', $result->items[0]->providerCode);
        $this->assertSame('909979576', $result->items[0]->externalProductId);
        $this->assertSame(151300, $result->items[0]->priceAmount);
        $this->assertSame(478300, $result->items[0]->oldPriceAmount);
        $this->assertSame(50, $result->total);
        $this->assertArrayHasKey('wildberries', $result->providerMeta);
        $this->assertArrayHasKey('took_ms', $result->providerMeta['wildberries']);
        $this->assertSame(5, $result->providerMeta['wildberries']['returned']);
    }

    public function test_search_gracefully_handles_playwright_down(): void
    {
        Cache::forget('playwright:reachable');

        Http::fake([
            '*/health' => Http::response(['error' => 'service down'], 503),
            '*/scrape' => Http::response(['error' => 'service down'], 503),
        ]);

        /** @var WildberriesProductProvider $provider */
        $provider = $this->app->make(WildberriesProductProvider::class);

        $this->assertFalse($provider->isEnabled());
    }
}
