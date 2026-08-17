<?php

declare(strict_types=1);

namespace App\Services\Providers\Ozon;

use App\Enums\ProviderCode;
use App\Exceptions\ProviderRateLimitException;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Guards the Ozon Seller API quota with a per-minute sliding window shared by
 * every worker through the cache store.
 */
class OzonRateLimitPolicy
{
    private const KEY = 'provider:ozon:rate';

    private const DECAY_SECONDS = 60;

    private const DEFAULT_LIMIT_PER_MINUTE = 2400;

    /**
     * Run the callback unless the provider quota for this minute is exhausted.
     *
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     *
     * @throws ProviderRateLimitException
     */
    public function attempt(callable $callback): mixed
    {
        $limit = $this->limitPerMinute();

        // A null/non-positive limit means "no client-side throttling".
        if ($limit <= 0) {
            return $callback();
        }

        $result = null;

        $executed = RateLimiter::attempt(
            self::KEY,
            $limit,
            Closure::fromCallable(function () use ($callback, &$result): void {
                $result = $callback();
            }),
            self::DECAY_SECONDS,
        );

        if ($executed === false) {
            throw new ProviderRateLimitException(
                ProviderCode::Ozon->value,
                RateLimiter::availableIn(self::KEY),
                sprintf('Local Ozon quota of %d requests per minute exhausted.', $limit),
            );
        }

        return $result;
    }

    private function limitPerMinute(): int
    {
        return (int) Config::get(
            'marketplace.providers.ozon.rate_limit_per_minute',
            self::DEFAULT_LIMIT_PER_MINUTE
        );
    }
}
