<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Playwright Scraping Service
    |--------------------------------------------------------------------------
    |
    | Ozon and Yandex Market data is collected by scraping their public
    | search pages through the in-cluster Playwright service, which the
    | providers reach over HTTP via PlaywrightScraperClient.
    |
    */

    'playwright' => [
        'base_url' => env('PLAYWRIGHT_URL', 'http://playwright:3000'),
        'timeout_ms' => (int) env('PLAYWRIGHT_TIMEOUT_MS', 20000),
    ],

    'drissionpage' => [
        'base_url' => env('DRISSIONPAGE_URL', 'http://drissionpage:8000'),
        'timeout_ms' => (int) env('DRISSIONPAGE_TIMEOUT_MS', 50000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketplace Providers
    |--------------------------------------------------------------------------
    |
    | Every provider is described by its concrete implementation class,
    | an enabled flag and provider-specific settings. The concrete
    | provider classes are resolved from the service container and
    | registered via the ProviderRegistry (see AppServiceProvider).
    |
    */

    'providers' => [
        'fake' => [
            'class' => \App\Services\Providers\Fake\FakeProductProvider::class,
            'enabled' => env('MARKETPLACE_FAKE_ENABLED', true),
            'display_name' => 'Demo Provider',
            'rate_limit_per_minute' => null,
            'cache_ttl_seconds' => 3600,
        ],

        'ozon' => [
            'class' => \App\Services\Providers\Ozon\OzonProductProvider::class,
            'enabled' => env('MARKETPLACE_OZON_ENABLED', true),
            'display_name' => 'Ozon',
            'search_url_template' => env('OZON_SEARCH_URL', 'https://www.ozon.ru/search/?text={query}'),
            'rate_limit_per_minute' => (int) env('OZON_RATE_LIMIT_PER_MINUTE', 30),
            'cache_ttl_seconds' => (int) env('OZON_CACHE_TTL_SECONDS', 900),
        ],

        'yandex_market' => [
            'class' => \App\Services\Providers\YandexMarket\YandexMarketProductProvider::class,
            'enabled' => env('MARKETPLACE_YANDEX_MARKET_ENABLED', true),
            'display_name' => 'Yandex Market',
            'search_url_template' => env('YANDEX_SEARCH_URL', 'https://market.yandex.ru/search?text={query}'),
            'rate_limit_per_minute' => (int) env('YANDEX_MARKET_RATE_LIMIT_PER_MINUTE', 30),
            'cache_ttl_seconds' => (int) env('YANDEX_MARKET_CACHE_TTL_SECONDS', 900),
        ],

        'wildberries' => [
            'class' => \App\Services\Providers\Wildberries\WildberriesProductProvider::class,
            'enabled' => env('MARKETPLACE_WILDBERRIES_ENABLED', true),
            'display_name' => 'Wildberries',
            'search_url_template' => env('WILDBERRIES_SEARCH_URL', 'https://www.wildberries.ru/catalog/0/search.aspx?search={query}'),
            'rate_limit_per_minute' => (int) env('WILDBERRIES_RATE_LIMIT_PER_MINUTE', 30),
            'cache_ttl_seconds' => (int) env('WILDBERRIES_CACHE_TTL_SECONDS', 900),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Defaults
    |--------------------------------------------------------------------------
    */

    'search' => [
        'default_timeout_ms' => 15000,
        'default_per_page' => 20,
        'max_per_page' => 100,
        'cache_ttl_seconds' => 900,
        'cache_prefix' => 'product-search',
    ],
];
