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
 * Thin transport wrapper around the Ozon Seller API.
 *
 * Authentication uses the `Client-Id` / `Api-Key` header pair issued in the
 * seller cabinet. This client is intentionally READ-ONLY by convention: only
 * list/info endpoints are ever called from the provider layer, never any
 * endpoint that creates, updates or removes seller data.
 *
 * Transport failures are translated into the application's provider
 * exceptions so callers never have to reason about HTTP status codes.
 */
class OzonSellerClient
{
    private const DEFAULT_BASE_URL = 'https://api-seller.ozon.ru';

    private const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * @param array<string, mixed> $config Slice of `marketplace.providers.ozon`.
     */
    public function __construct(private readonly array $config) {}

    /**
     * Credentials are the only hard requirement: without them the provider
     * reports itself as disabled instead of failing at request time.
     */
    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->apiKey() !== '';
    }

    /**
     * POST a JSON payload and return the decoded response body.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ProviderAuthenticationException
     * @throws ProviderRateLimitException
     * @throws ProviderUnavailableException
     */
    public function postJson(string $endpoint, array $payload, int $timeoutMs = 5000): array
    {
        $url = $this->url($endpoint);

        try {
            $response = Http::withHeaders([
                'Client-Id' => $this->clientId(),
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
                'Ozon connection failed: ' . $e->getMessage(),
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

    public function providerCode(): string
    {
        return ProviderCode::Ozon->value;
    }

    private function url(string $endpoint): string
    {
        return $this->baseUrl() . '/' . ltrim($endpoint, '/');
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
                sprintf('Ozon rejected the credentials for [%s] with status %d.', $endpoint, $status),
                $status,
            );
        }

        if ($status === 429) {
            throw new ProviderRateLimitException(
                $this->providerCode(),
                $this->retryAfter($response),
                sprintf('Ozon rate limit hit on [%s].', $endpoint),
            );
        }

        throw new ProviderUnavailableException(
            $this->providerCode(),
            sprintf(
                'Ozon returned status %d for [%s]: %s',
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
            Log::warning('Ozon returned a non-JSON body on a successful response.', [
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

    private function clientId(): string
    {
        return trim((string) ($this->config['client_id'] ?? ''));
    }

    private function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }
}
