<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Clients\OzonSellerClient;
use App\Http\Clients\YandexMarketClient;
use App\Services\ProviderRegistry;
use App\Services\Providers\Fake\FakeProductProvider;
use App\Services\Providers\Ozon\OzonProductProvider;
use App\Services\Providers\YandexMarket\YandexMarketProductProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Concrete marketplace provider classes tagged as `product-providers`
     * and consumed by the ProviderRegistry.
     *
     * @var array<int, class-string<\App\Contracts\ProductProviderInterface>>
     */
    private const PRODUCT_PROVIDER_CLASSES = [
        FakeProductProvider::class,
        OzonProductProvider::class,
        YandexMarketProductProvider::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerMarketplaceClients();

        foreach (self::PRODUCT_PROVIDER_CLASSES as $providerClass) {
            $this->app->singleton($providerClass);
        }

        $this->app->tag(self::PRODUCT_PROVIDER_CLASSES, 'product-providers');

        $this->app->singleton(ProviderRegistry::class, static function (Application $app): ProviderRegistry {
            return new ProviderRegistry($app->tagged('product-providers'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Marketplace HTTP clients take their credentials as a plain config array,
     * so each one gets an explicit factory. Everything else in the provider
     * graph (mappers, rate-limit policies, post-processor) is autowirable.
     */
    private function registerMarketplaceClients(): void
    {
        $this->app->singleton(OzonSellerClient::class, static function (Application $app): OzonSellerClient {
            return new OzonSellerClient(
                (array) $app['config']->get('marketplace.providers.ozon', [])
            );
        });

        $this->app->singleton(YandexMarketClient::class, static function (Application $app): YandexMarketClient {
            return new YandexMarketClient(
                (array) $app['config']->get('marketplace.providers.yandex_market', [])
            );
        });
    }
}
