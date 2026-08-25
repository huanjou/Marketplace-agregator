<?php

declare(strict_types=1);

namespace App\Http\Clients;

use App\DTO\Marketplace\PlaywrightScrapeResponse;
use App\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Transport wrapper around the in-cluster Playwright scraping service.
 *
 * The service exposes a single `POST /scrape` endpoint per provider plus a
 * `GET /health` probe. Every transport problem — connection failure, non-2xx
 * status (502 ANTIBOT / 500 INTERNAL) or a body that is not JSON — surfaces as
 * a ProviderUnavailableException so the provider layer can degrade gracefully.
 */
final class PlaywrightScraperClient
{
    private const SERVICE_CODE = 'playwright';

    private const REACHABLE_CACHE_KEY = 'playwright:reachable';

    private const REACHABLE_CACHE_TTL_SECONDS = 30;

    private const CONNECT_TIMEOUT_SECONDS = 2;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutMs = 10000,
    ) {}

    /**
     * Scrape one provider search page. The returned items are raw snake_case
     * dicts — mapping into DTOs belongs to the per-provider mappers.
     *
     * When $searchUrl is given, the scraper opens that exact URL (a SERP
     * pre-built by the AI URL service with filters baked in) instead of
     * composing its own plain-text search link; the scraper side re-validates
     * the URL against the store's own domain before navigating.
     *
     * @throws ProviderUnavailableException
     */
    public function scrape(string $provider, string $query, int $page = 1, ?int $timeoutMs = null, ?string $searchUrl = null): PlaywrightScrapeResponse
    {
        $budgetMs = $timeoutMs ?? $this->timeoutMs;

        $body = [
            'provider' => $provider,
            'query' => $query,
            'page' => $page,
            'timeout_ms' => $budgetMs,
        ];

        if ($searchUrl !== null) {
            $body['url'] = $searchUrl;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(max(3, intdiv($budgetMs, 1000) + 2))
                ->retry(1, 200, throw: false)
                ->post($this->url('/scrape'), $body);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                $provider,
                sprintf('Playwright service unreachable at [%s]: %s', $this->baseUrl, $e->getMessage()),
                503,
                $e,
            );
        }

        if (! $response->successful()) {
            throw new ProviderUnavailableException(
                $provider,
                sprintf(
                    'Playwright scrape failed with status %d (%s): %s',
                    $response->status(),
                    $this->errorCode($response) ?? 'UNKNOWN',
                    $this->errorMessage($response),
                ),
                503,
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderUnavailableException(
                $provider,
                sprintf(
                    'Playwright scrape returned a non-JSON body for [%s]: %s',
                    $provider,
                    mb_substr(trim($response->body()), 0, 200),
                ),
                503,
            );
        }

        return new PlaywrightScrapeResponse(
            items: is_array($body['items'] ?? null) ? $body['items'] : [],
            meta: is_array($body['meta'] ?? null) ? $body['meta'] : [],
        );
    }

    /**
     * Raw `GET /health` payload: `{ status, pool: {...}, uptime_ms }`.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailableException
     */
    public function health(int $timeoutSeconds = 2): array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout($timeoutSeconds)
                ->get($this->url('/health'));
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                self::SERVICE_CODE,
                sprintf('Playwright health check could not connect to [%s]: %s', $this->baseUrl, $e->getMessage()),
                503,
                $e,
            );
        }

        if (! $response->successful()) {
            throw new ProviderUnavailableException(
                self::SERVICE_CODE,
                sprintf('Playwright health check returned status %d.', $response->status()),
                503,
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderUnavailableException(
                self::SERVICE_CODE,
                'Playwright health check returned a non-JSON body.',
                503,
            );
        }

        return $body;
    }

    /**
     * Cheap liveness gate used by `isEnabled()` on the scraping providers.
     * The verdict is cached for 30 seconds so a search burst pings the
     * service at most once.
     */
    public function isReachable(): bool
    {
        try {
            return (bool) Cache::remember(
                self::REACHABLE_CACHE_KEY . ':' . md5($this->baseUrl),
                self::REACHABLE_CACHE_TTL_SECONDS,
                function (): bool {
                    try {
                        $health = $this->health(timeoutSeconds: 1);
                    } catch (ProviderUnavailableException) {
                        // A negative verdict is cached too, so a down service
                        // is not pinged again on every single search.
                        return false;
                    }

                    $status = $health['status'] ?? null;

                    return $status === null || $status === 'ok';
                },
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    private function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        return is_string($code) ? $code : null;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return mb_substr(trim($response->body()), 0, 200);
    }
}
