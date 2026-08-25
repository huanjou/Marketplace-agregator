<?php

declare(strict_types=1);

namespace App\Http\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transport wrapper around the Perplexity chat completions API.
 *
 * The API is OpenAI-compatible: a single `POST {base_url}/chat/completions`
 * call with bearer auth. Every transport problem (connection failure, non-2xx
 * status, malformed JSON) is swallowed into a `null` return so AI-assisted
 * features can degrade gracefully — they must never be able to fail a search.
 */
final class PerplexityClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.perplexity.ai',
        private readonly string $model = 'sonar',
        private readonly int $timeoutMs = 15000,
        private readonly int $maxTokens = 700,
        private readonly float $temperature = 0.2,
    ) {}

    public function isEnabled(): bool
    {
        return $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Run one completion and return the assistant message content, or null on
     * any failure (logged, never thrown).
     */
    public function complete(string $systemPrompt, string $userPrompt): ?string
    {
        return $this->post($this->basePayload($systemPrompt, $userPrompt));
    }

    /**
     * Run one completion constrained by a JSON schema (OpenAI-compatible
     * structured outputs) and return the decoded assistant payload, or null on
     * any failure — including a response that is not valid JSON.
     *
     * @param array<string, mixed> $jsonSchema
     * @return array<string, mixed>|null
     */
    public function completeStructured(string $systemPrompt, string $userPrompt, array $jsonSchema): ?array
    {
        $payload = $this->basePayload($systemPrompt, $userPrompt);
        $payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'search_urls',
                'strict' => true,
                'schema' => $jsonSchema,
            ],
        ];

        $content = $this->post($payload);

        if ($content === null) {
            return null;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            Log::warning('Perplexity structured response was not valid JSON.', [
                'content' => mb_substr($content, 0, 300),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(string $systemPrompt, string $userPrompt): array
    {
        return [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
    }

    private function post(array $body): ?string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout((int) ceil($this->timeoutMs / 1000))
                // Transient egress hiccups (connect timeouts) happen — one
                // quick retry keeps the AI path alive without failing searches.
                // Client errors (4xx) are deterministic, so they are not retried.
                ->retry(2, 300, static function ($exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && ($exception->response->serverError() || $exception->response->status() === 429);
                })
                ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $body);

            return $this->extractContent($response);
        } catch (Throwable $e) {
            Log::warning('Perplexity completion failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'model' => $this->model,
            ]);

            return null;
        }
    }

    private function extractContent(Response $response): ?string
    {
        if (! $response->successful()) {
            Log::warning('Perplexity returned a non-2xx response.', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            Log::warning('Perplexity response held no assistant content.');

            return null;
        }

        return trim($content);
    }
}
