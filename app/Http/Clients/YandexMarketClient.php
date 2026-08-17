<?php

declare(strict_types=1);

namespace App\Http\Clients;

use App\Enums\ProviderCode;
use App\Exceptions\ProviderAuthenticationException;
use App\Exceptions\ProviderRateLimitException;
use App\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin transport wrapper around the Yandex Market Partner API.
 *
 * Authentication uses a single `Api-Key` header (OAuth-less API keys issued in
 * the partner cabinet). Like its Ozon counterpart this client is READ-ONLY by
 * convention: the provider layer only ever calls mapping/listing endpoints,
 * never price, stock or order mutations.
 *
 * The Partner API is versioned in the path (`/v2/...`). The version prefix is
 * added here unless the configured base URL already carries one, so both
 * `https://api.partner.market.yandex.ru` and `.../v2` work.
 */
class YandexMarketClient
{
    private const DEFAULT_BASE_URL = 'https://api.partner.market.yandex.ru';

    private const API_VERSION = 'v2';

    private const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * @param array<string, mixed> $config Slice of `marketplace.providers.yandex_market`.
     */
    public function __construct(private readonly array $config) {}

    /**
     * A business id is as essential as the key itself: every catalogue
     * endpoint we use is scoped to a business.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->businessId() !== '';
    }

    /**
     * POST a JSON payload and return the decoded response body.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $query Query-string parameters (limit, page_token, ...).
     * @return array<string, mixed>
     *
     * @throws ProviderAuthenticationException
     * @throws ProviderRateLimitException
     * @throws ProviderUnavailableException
     */
    public function postJson(string $endpoint, array $payload, int $timeoutMs = 5000, array $query = []): array
    {
        $url = $this->url($endpoint, $query);

        try {
            $response = Http::withHeaders([
                'Api-Key' => $this->apiKey(),
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeoutSeconds($timeoutMs))
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                $this->providerCode(),
                'Yandex Market connection failed: ' . $e->getMessage(),
                503,
                $e,
            );
        }

        $this->guardAgainstFailure($response, $endpoint);

        return $this->decode($response, $endpoint);
    }

    public function baseUrl(): string
    {
        $baseUrl = trim((string) ($this->config['base_url'] ?? ''));

        return rtrim($baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL, '/');
    }

    public function businessId(): string
    {
        return trim((string) ($this->config['business_id'] ?? ''));
    }

    public function campaignId(): string
    {
        return trim((string) ($this->config['campaign_id'] ?? ''));
    }

    public function providerCode(): string
    {
        return ProviderCode::YandexMarket->value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function url(string $endpoint, array $query = []): string
    {
        $base = $this->baseUrl();

        if (preg_match('#/v\d+$#', $base) !== 1) {
            $base .= '/' . self::API_VERSION;
        }

        $url = $base . '/' . ltrim($endpoint, '/');
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /**
     * @throws ProviderAuthenticationException
     * @throws ProviderRateLimitException
     * @throws ProviderUnavailableException
     */
    private function guardAgainstFailure(Response $response, string $endpoint): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new ProviderAuthenticationException(
                $this->providerCode(),
                sprintf('Yandex Market rejected the credentials for [%s] with status %d.', $endpoint, $status),
                $status,
            );
        }

        if ($status === 420 || $status === 429) {
            // The Partner API historically answers 420 ("Enhance your calm")
            // for quota violations alongside the standard 429.
            throw new ProviderRateLimitException(
                $this->providerCode(),
                $this->retryAfter($response),
                sprintf('Yandex Market rate limit hit on [%s].', $endpoint),
            );
        }

        throw new ProviderUnavailableException(
            $this->providerCode(),
            sprintf(
                'Yandex Market returned status %d for [%s]: %s',
                $status,
                $endpoint,
                mb_substr(trim($response->body()), 0, 500),
            ),
            $status >= 500 ? 503 : $status,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $endpoint): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            Log::warning('Yandex Market returned a non-JSON body on a successful response.', [
                'provider_code' => $this->providerCode(),
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            return [];
        }

        return $decoded;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    private function timeoutSeconds(int $timeoutMs): float
    {
        $configured = $this->config['timeout'] ?? null;
        $timeout = $timeoutMs > 0 ? $timeoutMs : (int) ($configured ?? 5000);

        return max(1.0, $timeout / 1000);
    }

    private function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }
}
