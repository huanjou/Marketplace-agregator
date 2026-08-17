<?php

declare(strict_types=1);

namespace App\Services\Providers\YandexMarket;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\Enums\Availability;
use App\Enums\ProviderCode;

/**
 * Maps raw Playwright scrape items (Yandex Market search results) into
 * ExternalProductData DTOs.
 *
 * Each item in the scrape response follows this contract:
 *   external_id, title, brand, price_amount (kopecks), old_price_amount,
 *   currency, image_url, product_url, rating_value, rating_count,
 *   availability_status, stock_quantity, raw_payload.
 */
class YandexMarketProductMapper
{
    /**
     * Map an array of raw scrape items into ExternalProductData DTOs.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return ExternalProductData[]
     */
    public function mapMany(array $rawItems, ProductSearchQuery $query): array
    {
        $items = [];

        foreach ($rawItems as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $mapped = $this->mapOne($raw);

            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function mapOne(array $raw): ?ExternalProductData
    {
        $title = trim((string) ($raw['title'] ?? ''));
        $externalId = $this->resolveExternalId($raw);

        if ($title === '' || $externalId === '') {
            return null;
        }

        return new ExternalProductData(
            providerCode: ProviderCode::YandexMarket->value,
            externalProductId: $externalId,
            externalOfferId: null,
            title: $title,
            brand: $this->stringOrNull($raw['brand'] ?? null),
            description: null,
            priceAmount: $this->intOrNull($raw['price_amount'] ?? null),
            oldPriceAmount: $this->intOrNull($raw['old_price_amount'] ?? null),
            currency: $this->resolveCurrency($raw),
            categoryExternalId: null,
            categoryName: null,
            imageUrls: $this->resolveImageUrls($raw),
            productUrl: $this->stringOrNull($raw['product_url'] ?? null),
            availabilityStatus: $this->resolveAvailability($raw),
            stockQuantity: $this->intOrNull($raw['stock_quantity'] ?? null),
            ratingValue: $this->floatOrNull($raw['rating_value'] ?? null),
            ratingCount: $this->intOrNull($raw['rating_count'] ?? null),
            rawPayload: $raw,
        );
    }

    private function resolveExternalId(array $raw): string
    {
        $id = trim((string) ($raw['external_id'] ?? ''));

        if ($id !== '') {
            return $id;
        }

        // Fallback: deterministic id from product_url or title
        $seed = $raw['product_url'] ?? $raw['title'] ?? '';

        return $seed !== '' ? 'yandex:' . md5((string) $seed) : '';
    }

    private function resolveCurrency(array $raw): string
    {
        $currency = trim((string) ($raw['currency'] ?? ''));

        return $currency !== '' ? mb_strtoupper($currency) : 'RUB';
    }

    /**
     * @return string[]
     */
    private function resolveImageUrls(array $raw): array
    {
        $url = $this->stringOrNull($raw['image_url'] ?? null);

        return $url !== null ? [$url] : [];
    }

    private function resolveAvailability(array $raw): ?string
    {
        $status = $this->stringOrNull($raw['availability_status'] ?? null);

        if ($status === null) {
            return null;
        }

        // Normalize known statuses to our enum values
        return match (mb_strtolower($status)) {
            'in_stock', 'instock' => Availability::InStock->value,
            'out_of_stock', 'outofstock' => Availability::OutOfStock->value,
            'archived' => Availability::Archived->value,
            default => $status,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
