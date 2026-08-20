<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Provider;
use App\Services\Providers\Fake\FakeProductProvider;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        Provider::updateOrCreate(
            ['code' => 'fake'],
            [
                'name' => 'Demo Provider',
                'provider_class' => FakeProductProvider::class,
                'enabled' => false,
                'supports_realtime_search' => true,
                'supports_catalog_sync' => false,
                'capabilities' => [
                    'price_range',
                    'brand',
                    'category',
                    'rating',
                    'availability',
                ],
                'rate_limit_per_minute' => null,
                'cache_ttl_seconds' => 3600,
            ]
        );

        Provider::updateOrCreate(
            ['code' => 'ozon'],
            [
                'name' => 'Ozon',
                'provider_class' => 'App\\Services\\Providers\\Ozon\\OzonProductProvider',
                'enabled' => true,
                'supports_realtime_search' => true,
                'supports_catalog_sync' => true,
                'capabilities' => [
                    'price_range',
                    'brand',
                    'category',
                    'availability',
                ],
                'rate_limit_per_minute' => 2400,
                'cache_ttl_seconds' => 300,
            ]
        );

        Provider::updateOrCreate(
            ['code' => 'yandex_market'],
            [
                'name' => 'Yandex Market',
                'provider_class' => 'App\\Services\\Providers\\YandexMarket\\YandexMarketProductProvider',
                'enabled' => true,
                'supports_realtime_search' => true,
                'supports_catalog_sync' => true,
                'capabilities' => [
                    'price_range',
                    'brand',
                    'category',
                    'availability',
                ],
                'rate_limit_per_minute' => 100,
                'cache_ttl_seconds' => 300,
            ]
        );

        Provider::updateOrCreate(
            ['code' => 'wildberries'],
            [
                'name' => 'Wildberries',
                'provider_class' => 'App\\Services\\Providers\\Wildberries\\WildberriesProductProvider',
                'enabled' => true,
                'supports_realtime_search' => true,
                'supports_catalog_sync' => false,
                'capabilities' => [
                    'price_range',
                    'availability',
                ],
                'rate_limit_per_minute' => 30,
                'cache_ttl_seconds' => 300,
            ]
        );
    }
}
