<?php

declare(strict_types=1);

namespace App\DTO\Marketplace;

/**
 * Raw payload returned by the Playwright scraping service.
 *
 * Items are left untouched (snake_case dicts straight from Node) — the
 * per-provider mappers own the translation into ExternalProductData.
 */
final readonly class PlaywrightScrapeResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $meta  provider, took_ms, extraction_mode, total_hint
     */
    public function __construct(
        public array $items,
        public array $meta = [],
    ) {}

    public function extractionMode(): ?string
    {
        $mode = $this->meta['extraction_mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }

    public function totalHint(): int
    {
        return (int) ($this->meta['total_hint'] ?? count($this->items));
    }

    public function tookMs(): int
    {
        return (int) ($this->meta['took_ms'] ?? 0);
    }
}
