<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\Livewire\PublicProductSearch;
use App\Models\Provider;
use App\Services\Ai\PerplexitySearchUrlService;
use App\Services\ProductSearchService;
use App\Services\ResultAggregator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guards the Perplexity "search URL builder" approach: the model translates a
 * free-text query into ready-to-open marketplace SERP URLs (filters baked in),
 * which are then handed to the existing scrapers. Covers URL extraction,
 * hard validation, caching, pipeline wiring and the Livewire status flow.
 */
class AiSearchUrlTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // In-memory cache keeps URL-resolution caching isolated per test.
        config(['cache.default' => 'array']);
    }

    public function test_returns_empty_map_without_api_key_and_makes_no_http_call(): void
    {
        config(['services.perplexity.key' => '']);
        Http::fake();

        $urls = app(PerplexitySearchUrlService::class)->urlsFor('компьютер до 100к');

        $this->assertSame([], $urls);
        Http::assertNothingSent();
    }

    public function test_builds_urls_from_perplexity_structured_response(): void
    {
        config(['services.perplexity.key' => 'pplx-test-key']);

        Http::fake([
            'https://api.perplexity.ai/*' => $this->completionResponse([
                'ozon_search_url' => 'https://www.ozon.ru/search/?text=компьютер&price_to=100000',
                'yandex_market_search_url' => 'https://market.yandex.ru/search?text=компьютер&price_to=100000',
                'wildberries_search_url' => 'https://www.wildberries.ru/catalog/0/search.aspx?search=компьютер&price_max=100000',
            ]),
        ]);

        $urls = app(PerplexitySearchUrlService::class)->urlsFor('компьютер до 100к');

        $this->assertSame([
            'ozon' => 'https://www.ozon.ru/search/?text=компьютер&price_to=100000',
            'yandex_market' => 'https://market.yandex.ru/search?text=компьютер&price_to=100000',
            'wildberries' => 'https://www.wildberries.ru/catalog/0/search.aspx?search=компьютер&price_max=100000',
        ], $urls);

        // The completion must be constrained by the strict JSON schema.
        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/chat/completions')
                && ($request['response_format']['type'] ?? null) === 'json_schema'
                && ($request['response_format']['json_schema']['strict'] ?? null) === true
                && in_array(
                    'ozon_search_url',
                    $request['response_format']['json_schema']['schema']['required'] ?? [],
                    true,
                );
        });
    }

    public function test_rejects_urls_violating_scheme_host_or_path(): void
    {
        config(['services.perplexity.key' => 'pplx-test-key']);

        Http::fake([
            'https://api.perplexity.ai/*' => $this->completionResponse([
                // A single product card, not a SERP/category — rejected.
                'ozon_search_url' => 'https://www.ozon.ru/product/sistemnyy-blok-123456/',
                // foreign host — rejected.
                'yandex_market_search_url' => 'https://evil.example.com/search?text=тест',
                // http scheme — rejected.
                'wildberries_search_url' => 'http://www.wildberries.ru/catalog/0/search.aspx?search=тест',
            ]),
        ]);

        $urls = app(PerplexitySearchUrlService::class)->urlsFor('тест');

        $this->assertSame([], $urls);
    }

    public function test_accepts_ozon_category_pages_with_price_filter(): void
    {
        config(['services.perplexity.key' => 'pplx-test-key']);

        Http::fake([
            'https://api.perplexity.ai/*' => $this->completionResponse([
                'ozon_search_url' => 'https://www.ozon.ru/category/sistemnye-bloki-do-100000-rubley/',
                'yandex_market_search_url' => '',
                'wildberries_search_url' => '',
            ]),
        ]);

        $urls = app(PerplexitySearchUrlService::class)->urlsFor('компьютер до 100к');

        $this->assertSame(
            ['ozon' => 'https://www.ozon.ru/category/sistemnye-bloki-do-100000-rubley/'],
            $urls,
        );
    }

    public function test_price_bounds_are_parsed_from_ai_urls(): void
    {
        $bounds = PerplexitySearchUrlService::priceBounds([
            'ozon' => 'https://www.ozon.ru/search/?text=ноутбуки&price_to=60000',
            'wildberries' => 'https://www.wildberries.ru/catalog/0/search.aspx?search=ноутбуки&price_max=60000&price_min=1000',
        ]);

        $this->assertSame(6_000_000, $bounds['max'], 'price bound must be in minor units');
        $this->assertSame(100_000, $bounds['min']);

        // Category slugs bake the budget into the path.
        $bounds = PerplexitySearchUrlService::priceBounds([
            'ozon' => 'https://www.ozon.ru/category/sistemnye-bloki-do-100000-rubley/',
        ]);

        $this->assertSame(10_000_000, $bounds['max']);
        $this->assertNull($bounds['min']);

        // The tightest bound wins when URLs disagree.
        $bounds = PerplexitySearchUrlService::priceBounds([
            'ozon' => 'https://www.ozon.ru/search/?text=x&price_to=60000',
            'yandex_market' => 'https://market.yandex.ru/search?text=x&price_to=70000',
        ]);

        $this->assertSame(6_000_000, $bounds['max']);
    }

    public function test_over_budget_offers_are_cut_by_the_aggregator(): void
    {
        $query = new ProductSearchQuery(
            text: 'ноутбуки',
            filters: new ProductSearchFilters(maxPriceAmount: 6_000_000),
        );

        $items = [
            new ExternalProductData(providerCode: 'ozon', title: 'в бюджете', priceAmount: 5_999_900),
            new ExternalProductData(providerCode: 'ozon', title: 'дороже бюджета', priceAmount: 10_000_000),
            new ExternalProductData(providerCode: 'wildberries', title: 'без цены', priceAmount: null),
        ];

        $result = app(ResultAggregator::class)->aggregate($items, $query);

        $titles = array_map(static fn ($item) => $item->title, $result->items);

        $this->assertContains('в бюджете', $titles);
        $this->assertContains('без цены', $titles);
        $this->assertNotContains('дороже бюджета', $titles);
        $this->assertSame(2, $result->total);
    }

    public function test_resolved_urls_are_cached_for_repeat_queries(): void
    {
        config(['services.perplexity.key' => 'pplx-test-key']);

        Http::fake([
            'https://api.perplexity.ai/*' => $this->completionResponse([
                'ozon_search_url' => 'https://www.ozon.ru/search/?text=наушники',
                'yandex_market_search_url' => '',
                'wildberries_search_url' => '',
            ]),
        ]);

        $service = app(PerplexitySearchUrlService::class);

        $first = $service->urlsFor('наушники');
        $second = $service->urlsFor('наушники');

        $this->assertSame(['ozon' => 'https://www.ozon.ru/search/?text=наушники'], $first);
        $this->assertSame($first, $second);

        // Only one completion call may leave the process.
        Http::assertSentCount(1);
    }

    public function test_explicit_search_urls_are_never_overridden_by_ai(): void
    {
        // The fake provider is the only one that runs without a live scraper.
        Provider::query()->where('code', 'fake')->update(['enabled' => true]);

        config(['services.perplexity.key' => 'pplx-test-key']);
        Http::fake([
            'https://api.perplexity.ai/*' => $this->completionResponse([
                'ozon_search_url' => 'https://www.ozon.ru/search/?text=ноутбук',
                'yandex_market_search_url' => '',
                'wildberries_search_url' => '',
            ]),
        ]);

        $explicit = ['fake' => 'https://www.ozon.ru/search/?text=ноутбук&price_to=50000'];

        $result = app(ProductSearchService::class)->search(new ProductSearchQuery(
            text: 'ноутбук',
            page: 1,
            perPage: 10,
            providerCodes: ['fake'],
            searchUrls: $explicit,
        ));

        // Callers that bring their own URLs must never trigger an AI call.
        Http::assertNothingSent();
        $this->assertSame($explicit, $result->providerMeta[PerplexitySearchUrlService::META_KEY]);
    }

    public function test_public_search_ui_walks_through_the_ai_link_pipeline(): void
    {
        config(['services.perplexity.key' => 'pplx-test-key']);

        $fixture = json_decode(
            file_get_contents(base_path('tests/Fixtures/wildberries/scrape_response.json')),
            true,
        );

        Http::fake([
            'https://api.perplexity.ai/*' => $this->completionResponse([
                'ozon_search_url' => '',
                'yandex_market_search_url' => '',
                'wildberries_search_url' => 'https://www.wildberries.ru/catalog/0/search.aspx?search=подушка&price_max=2000',
            ]),
            '*/health' => Http::response(['status' => 'ok', 'pool' => [], 'uptime_ms' => 60000], 200),
            '*/scrape' => Http::response($fixture, 200),
        ]);

        Livewire::test(PublicProductSearch::class)
            ->set('query', 'подушка до 2000')
            ->set('providerCodes', ['wildberries'])
            ->call('search')
            ->assertSet('status', 'Ищем ссылки...')
            ->call('resolveSearchUrls')
            ->assertSet('status', 'Собираем товары с маркетплейсов...')
            ->assertSet(
                'searchUrls.wildberries',
                'https://www.wildberries.ru/catalog/0/search.aspx?search=подушка&price_max=2000',
            )
            ->call('runScrape')
            ->assertSet('searched', true)
            ->assertSet('status', null)
            ->assertSet('aiUrlsApplied', true)
            // The AI budget folded into the filters must be remembered so
            // page flips hit the same cache fingerprint.
            ->assertSet('resultFilters.max_price_amount', 200000)
            ->assertSee('Ссылки с фильтрами подобраны ИИ')
            ->call('gotoPage', 2)
            ->assertSet('page', 2)
            ->assertSet('searched', true);

        // The scraper request must carry the AI-built URL override — and it
        // must be the ONLY one: the page flip is served from the cached set.
        $scrapeCalls = Http::recorded(
            static fn ($request, $response): bool => str_contains($request->url(), '/scrape')
        );

        $this->assertCount(1, $scrapeCalls, 'page flips must not re-run the scrapers');

        [$firstScrape] = $scrapeCalls->first();

        $this->assertSame(
            'https://www.wildberries.ru/catalog/0/search.aspx?search=подушка&price_max=2000',
            $firstScrape['url'] ?? null,
        );
    }

    /**
     * Perplexity chat-completions envelope holding one structured answer.
     *
     * @param array<string, string> $urls
     */
    private function completionResponse(array $urls): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ],
        ]);
    }
}
