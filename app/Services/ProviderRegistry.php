<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProductProviderInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Central registry that holds every ProductProviderInterface implementation
 * available to the application. Providers are resolved via a tagged service
 * binding declared in AppServiceProvider.
 */
class ProviderRegistry
{
    /** @var Collection<string, ProductProviderInterface> */
    private Collection $providers;

    /**
     * @param iterable<ProductProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = collect();

        foreach ($providers as $provider) {
            $code = $provider->code();

            if ($this->providers->has($code)) {
                throw new InvalidArgumentException(
                    "Duplicate provider code [{$code}] registered."
                );
            }

            $this->providers->put($code, $provider);
        }
    }

    /**
     * @return Collection<string, ProductProviderInterface>
     */
    public function all(): Collection
    {
        return $this->providers;
    }

    /**
     * @return Collection<string, ProductProviderInterface>
     */
    public function enabled(): Collection
    {
        return $this->providers->filter(
            static fn (ProductProviderInterface $provider) => $provider->isEnabled()
        );
    }

    public function get(string $code): ProductProviderInterface
    {
        if (! $this->providers->has($code)) {
            throw new InvalidArgumentException(
                "Provider [{$code}] is not registered."
            );
        }

        return $this->providers->get($code);
    }

    public function has(string $code): bool
    {
        return $this->providers->has($code);
    }

    /**
     * Return the subset of registered providers that match any of the given
     * provider codes. When the given list is empty, fall back to every
     * enabled provider.
     *
     * @param string[] $codes
     * @return Collection<string, ProductProviderInterface>
     */
    public function matching(array $codes): Collection
    {
        if (empty($codes)) {
            return $this->enabled();
        }

        return $this->providers->filter(
            static fn (ProductProviderInterface $provider, string $key) => in_array($key, $codes, true)
        );
    }

    /**
     * @return string[]
     */
    public function codes(): array
    {
        return $this->providers->keys()->all();
    }
}
