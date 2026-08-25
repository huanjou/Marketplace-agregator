<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Clients\PerplexityClient;
use App\Http\Clients\PlaywrightScraperClient;
use App\Services\ProviderRegistry;
use App\Services\Providers\Fake\FakeProductProvider;
use App\Services\Providers\Ozon\OzonProductProvider;
use App\Services\Providers\YandexMarket\YandexMarketProductProvider;
use App\Services\Providers\Wildberries\WildberriesProductProvider;
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
        WildberriesProductProvider::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerScraperClient();
        $this->registerPerplexityClient();

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
     * Both scraping providers talk to the same Playwright service, so a single
     * shared client is built from config here. Everything else in the provider
     * graph (mappers, rate-limit policies, post-processor) is autowirable.
     */
    private function registerScraperClient(): void
    {
        $this->app->singleton(PlaywrightScraperClient::class, static function (Application $app): PlaywrightScraperClient {
            return new PlaywrightScraperClient(
                baseUrl: (string) $app['config']->get('marketplace.playwright.base_url'),
                timeoutMs: (int) $app['config']->get('marketplace.playwright.timeout_ms'),
            );
        });

        $this->app->when(OzonProductProvider::class)
            ->needs(PlaywrightScraperClient::class)
            ->give(static function (Application $app): PlaywrightScraperClient {
                return new PlaywrightScraperClient(
                    baseUrl: (string) $app['config']->get('marketplace.drissionpage.base_url'),
                    timeoutMs: (int) $app['config']->get('marketplace.drissionpage.timeout_ms'),
                );
            });
    }

    /**
     * The Perplexity client is built from config here; an empty API key keeps
     * the client in a disabled state instead of breaking the container.
     */
    private function registerPerplexityClient(): void
    {
        $this->app->singleton(PerplexityClient::class, static function (Application $app): PerplexityClient {
            return new PerplexityClient(
                apiKey: (string) $app['config']->get('services.perplexity.key', ''),
                baseUrl: (string) $app['config']->get('services.perplexity.base_url', 'https://api.perplexity.ai'),
                model: (string) $app['config']->get('services.perplexity.model', 'sonar'),
                timeoutMs: (int) $app['config']->get('services.perplexity.timeout_ms', 15000),
                maxTokens: (int) $app['config']->get('services.perplexity.max_tokens', 700),
                temperature: (float) $app['config']->get('services.perplexity.temperature', 0.2),
            );
        });
    }
}
