<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Marketplace Providers
    |--------------------------------------------------------------------------
    |
    | Every provider is described by its concrete implementation class,
    | an enabled flag and provider-specific credentials. The concrete
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
            'enabled' => env('MARKETPLACE_OZON_ENABLED', false),
            'display_name' => 'Ozon',
            'client_id' => env('OZON_CLIENT_ID'),
            'api_key' => env('OZON_API_KEY'),
            'base_url' => env('OZON_BASE_URL', 'https://api-seller.ozon.ru'),
            'rate_limit_per_minute' => 2400,
            'cache_ttl_seconds' => 300,
        ],

        'yandex_market' => [
            'class' => \App\Services\Providers\YandexMarket\YandexMarketProductProvider::class,
            'enabled' => env('MARKETPLACE_YANDEX_ENABLED', false),
            'display_name' => 'Yandex Market',
            'api_key' => env('YANDEX_MARKET_API_KEY'),
            'business_id' => env('YANDEX_MARKET_BUSINESS_ID'),
            'campaign_id' => env('YANDEX_MARKET_CAMPAIGN_ID'),
            'base_url' => env('YANDEX_MARKET_BASE_URL', 'https://api.partner.market.yandex.ru'),
            'rate_limit_per_minute' => 100,
            'cache_ttl_seconds' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Defaults
    |--------------------------------------------------------------------------
    */

    'search' => [
        'default_timeout_ms' => 5000,
        'default_per_page' => 20,
        'max_per_page' => 100,
        'cache_ttl_seconds' => 300,
        'cache_prefix' => 'product-search',
    ],
];
