<?php

declare(strict_types=1);

namespace App\Services\Providers\Ozon;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\Enums\Availability;
use App\Enums\ProviderCode;

/**
 * Translates the normalised tiles returned by the Playwright scraper into
 * ExternalProductData.
 *
 * Payload contract (one dict per product tile, snake_case, built by
 * `docker/playwright/scrapers/ozon.js`):
 *
 *   external_id, title, brand, price_amount, old_price_amount, currency,
 *   image_url, product_url, rating_value, rating_count, availability_status,
 *   stock_quantity, raw_payload
 *
 * Monetary values already arrive as integer minor units (kopecks) and the
 * currency as an ISO string. A scraped page can always be incomplete — a tile
 * may lack a price, a rating or a brand — so every access is null-tolerant and
 * an unusable tile is dropped rather than raising.
 */
class OzonProductMapper
{
    /**
     * Map a whole scrape response. Tiles without a title are dropped: they are
     * promo/banner slots rather than products.
     *
     * The query is accepted for symmetry with the other providers and to keep
     * the door open for query-aware mapping; no local filtering happens here,
     * because Ozon's own search engine already answered the text query and
     * re-filtering by substring would discard legitimately relevant results.
     *
     * @param array<int, mixed> $rawItems
     * @return array<int, ExternalProductData>
     */
    public function mapMany(array $rawItems, ProductSearchQuery $query): array
    {
        $items = [];

        foreach ($rawItems as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $item = $this->mapOne($raw);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapOne(array $raw): ?ExternalProductData
    {
        $title = trim((string) ($raw['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $stock = $this->intOrNull($raw['stock_quantity'] ?? null);

        return new ExternalProductData(
            providerCode: ProviderCode::Ozon->value,
            externalProductId: $this->externalProductId($raw),
            // The public search page exposes no seller offer id, only the
            // Ozon-wide product id.
            externalOfferId: null,
            title: $title,
            brand: $this->stringOrNull($raw['brand'] ?? null),
            // Descriptions live on the product page, not on a search tile.
            description: null,
            priceAmount: $this->minorUnits($raw['price_amount'] ?? null),
            oldPriceAmount: $this->minorUnits($raw['old_price_amount'] ?? null),
            currency: $this->currency($raw),
            categoryExternalId: null,
            categoryName: null,
            imageUrls: $this->imageUrls($raw),
            productUrl: $this->stringOrNull($raw['product_url'] ?? null),
            availabilityStatus: $this->availability($raw['availability_status'] ?? null, $stock),
            stockQuantity: $stock,
            ratingValue: $this->floatOrNull($raw['rating_value'] ?? null),
            ratingCount: $this->intOrNull($raw['rating_count'] ?? null),
            rawPayload: $this->rawPayload($raw),
        );
    }

    /**
     * The scraper usually recovers the numeric Ozon product id; when the tile
     * hides it, a stable hash of the product URL keeps de-duplication and the
     * offer's database identity working across searches.
     *
     * @param array<string, mixed> $raw
     */
    private function externalProductId(array $raw): string
    {
        $externalId = trim((string) ($raw['external_id'] ?? ''));

        if ($externalId !== '') {
            return $externalId;
        }

        return 'ozon:' . md5((string) ($raw['product_url'] ?? $raw['title'] ?? ''));
    }

    /**
     * Prices are already integer kopecks; anything non-numeric or non-positive
     * means "the tile did not show a price".
     */
    private function minorUnits(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = (int) $value;

        return $amount > 0 ? $amount : null;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function currency(array $raw): string
    {
        $currency = trim((string) ($raw['currency'] ?? ''));

        return $currency !== '' ? mb_strtoupper($currency) : 'RUB';
    }

    /**
     * A tile carries a single thumbnail; the DTO keeps a list so the product
     * page scraper can add more later without a signature change.
     *
     * @param array<string, mixed> $raw
     * @return string[]
     */
    private function imageUrls(array $raw): array
    {
        $url = $this->stringOrNull($raw['image_url'] ?? null);

        return $url !== null ? [$url] : [];
    }

    /**
     * The scraper reports `in_stock` / `out_of_stock` / null. Unknown spellings
     * degrade to `unknown`, which the aggregator treats as "no evidence"
     * rather than as an out-of-stock offer.
     */
    private function availability(mixed $status, ?int $stock): string
    {
        if (is_string($status) && trim($status) !== '') {
            return (Availability::tryFrom(trim($status)) ?? Availability::Unknown)->value;
        }

        if ($stock !== null) {
            return $stock > 0
                ? Availability::InStock->value
                : Availability::OutOfStock->value;
        }

        return Availability::Unknown->value;
    }

    /**
     * Keep whatever source snippet the scraper attached; otherwise store the
     * normalised tile itself so the payload is never empty for debugging.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function rawPayload(array $raw): array
    {
        return is_array($raw['raw_payload'] ?? null)
            ? $raw['raw_payload']
            : ['source' => $raw];
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
