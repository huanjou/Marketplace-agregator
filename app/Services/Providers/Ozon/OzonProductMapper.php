<?php

declare(strict_types=1);

namespace App\Services\Providers\Ozon;

use App\DTO\Marketplace\ExternalProductData;
use App\Enums\Availability;
use App\Enums\ProviderCode;

/**
 * Translates an Ozon Seller API product row into ExternalProductData.
 *
 * Endpoint & shape assumption
 * ---------------------------
 * Rows are the merge of `POST /v3/product/list` (ids, offer ids, stock flags)
 * and `POST /v2/product/info/list` (name, prices, images, category), i.e. the
 * elements of `result.items[]`. The fields consumed here are assumed to be:
 *
 *   product_id, offer_id, name, price, old_price, marketing_price,
 *   currency_code, images[] (string|{file_name}), primary_image,
 *   description_category_id|category_id, category_name, stocks{present,coming},
 *   has_stocks, visible, archived, rating, description
 *
 * These names were NOT verified against a live Ozon account, therefore every
 * access is null-tolerant and an unexpected shape degrades to a sparse DTO
 * rather than an exception. A smoke test against real credentials is required
 * before this mapper is trusted in production.
 */
class OzonProductMapper
{
    private const PRODUCT_BASE_URL = 'https://www.ozon.ru/product/';

    private const IMAGE_KEYS = ['images', 'images360', 'color_image'];

    /**
     * @param array<string, mixed> $ozonRow
     */
    public function map(array $ozonRow): ExternalProductData
    {
        $externalProductId = $this->stringOrNull($ozonRow['product_id'] ?? $ozonRow['id'] ?? null);
        $stock = $this->stockQuantity($ozonRow);

        return new ExternalProductData(
            providerCode: ProviderCode::Ozon->value,
            externalProductId: $externalProductId,
            externalOfferId: $this->stringOrNull($ozonRow['offer_id'] ?? null),
            title: trim((string) ($ozonRow['name'] ?? '')),
            brand: $this->brand($ozonRow),
            description: $this->stringOrNull($ozonRow['description'] ?? null),
            priceAmount: $this->minorUnits($ozonRow['price'] ?? null),
            oldPriceAmount: $this->minorUnits($ozonRow['old_price'] ?? null),
            currency: $this->currency($ozonRow),
            categoryExternalId: $this->stringOrNull(
                $ozonRow['description_category_id'] ?? $ozonRow['category_id'] ?? null
            ),
            categoryName: $this->stringOrNull($ozonRow['category_name'] ?? $ozonRow['type_name'] ?? null),
            imageUrls: $this->imageUrls($ozonRow),
            productUrl: $externalProductId !== null ? self::PRODUCT_BASE_URL . $externalProductId : null,
            availabilityStatus: $this->availability($ozonRow, $stock),
            stockQuantity: $stock,
            ratingValue: $this->floatOrNull($ozonRow['rating'] ?? null),
            ratingCount: $this->intOrNull($ozonRow['rating_count'] ?? $ozonRow['reviews_count'] ?? null),
            rawPayload: $ozonRow,
        );
    }

    /**
     * Ozon sends monetary values as decimal strings ("1099.00"). Rounding
     * rather than truncating avoids losing a kopeck to float representation.
     */
    private function minorUnits(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = (int) round((float) $value * 100);

        return $amount > 0 ? $amount : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function currency(array $row): string
    {
        $currency = trim((string) ($row['currency_code'] ?? ''));

        return $currency !== '' ? mb_strtoupper($currency) : 'RUB';
    }

    /**
     * The seller API exposes no dedicated brand field; when present it arrives
     * as an attribute row, so a couple of likely spellings are probed.
     *
     * @param array<string, mixed> $row
     */
    private function brand(array $row): ?string
    {
        return $this->stringOrNull($row['brand'] ?? $row['vendor'] ?? $row['brand_name'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     * @return string[]
     */
    private function imageUrls(array $row): array
    {
        $urls = [];

        $primary = $this->imageUrl($row['primary_image'] ?? null);

        if ($primary !== null) {
            $urls[] = $primary;
        }

        foreach (self::IMAGE_KEYS as $key) {
            $images = $row[$key] ?? null;

            if (! is_array($images)) {
                $single = $this->imageUrl($images);

                if ($single !== null) {
                    $urls[] = $single;
                }

                continue;
            }

            foreach ($images as $image) {
                $url = $this->imageUrl($image);

                if ($url !== null) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Images arrive either as plain URLs or as `{"file_name": "https://..."}`.
     */
    private function imageUrl(mixed $image): ?string
    {
        if (is_string($image)) {
            return trim($image) !== '' ? trim($image) : null;
        }

        if (is_array($image)) {
            return $this->stringOrNull($image['file_name'] ?? $image['url'] ?? null);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stockQuantity(array $row): ?int
    {
        $stocks = $row['stocks'] ?? null;

        if (is_array($stocks)) {
            // `present` is the sellable quantity; `reserved` is already
            // excluded from it by Ozon.
            $present = $this->intOrNull($stocks['present'] ?? null);

            if ($present !== null) {
                return max(0, $present);
            }

            $coming = $this->intOrNull($stocks['coming'] ?? null);

            if ($coming !== null) {
                return max(0, $coming);
            }
        }

        return $this->intOrNull($row['stock'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function availability(array $row, ?int $stock): string
    {
        if (($row['archived'] ?? false) === true) {
            return Availability::Archived->value;
        }

        $hasStocks = $row['has_stocks'] ?? null;

        if (is_bool($hasStocks)) {
            return $hasStocks
                ? Availability::InStock->value
                : Availability::OutOfStock->value;
        }

        if ($stock !== null) {
            return $stock > 0
                ? Availability::InStock->value
                : Availability::OutOfStock->value;
        }

        return Availability::Unknown->value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && $value !== '0' ? $value : null;
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
