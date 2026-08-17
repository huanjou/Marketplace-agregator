<?php

declare(strict_types=1);

namespace App\Services\Providers\YandexMarket;

use App\DTO\Marketplace\ExternalProductData;
use App\Enums\Availability;
use App\Enums\ProviderCode;

/**
 * Translates a Yandex Market Partner API offer mapping into ExternalProductData.
 *
 * Endpoint & shape assumption
 * ---------------------------
 * Rows are the elements of `result.offerMappings[]` returned by
 * `POST /v2/businesses/{businessId}/offer-mappings`, each shaped as
 * `{"offer": {...}, "mapping": {...}}`. The fields consumed here are assumed
 * to be:
 *
 *   offer:   offerId, name, vendor, description, category, pictures[],
 *            basicPrice{value,currencyId,discountBase}, archived, cardStatus
 *   mapping: marketSku, marketSkuName, marketCategoryId, marketCategoryName
 *
 * These names were NOT verified against a live partner account, therefore every
 * access is null-tolerant and an unexpected shape degrades to a sparse DTO
 * rather than an exception. A smoke test against real credentials is required
 * before this mapper is trusted in production.
 *
 * Note that the partner API describes the seller's own catalogue and returns no
 * public storefront URL; one is synthesised from `marketSku` when available.
 */
class YandexMarketProductMapper
{
    private const PRODUCT_BASE_URL = 'https://market.yandex.ru/product/';

    /**
     * @param array<string, mixed> $yandexOffer An offer mapping row, or a bare offer.
     */
    public function map(array $yandexOffer): ExternalProductData
    {
        $offer = is_array($yandexOffer['offer'] ?? null) ? $yandexOffer['offer'] : $yandexOffer;
        $mapping = is_array($yandexOffer['mapping'] ?? null) ? $yandexOffer['mapping'] : [];

        $offerId = $this->stringOrNull($offer['offerId'] ?? $offer['shopSku'] ?? null);
        $marketSku = $this->stringOrNull($mapping['marketSku'] ?? null);
        $price = is_array($offer['basicPrice'] ?? null) ? $offer['basicPrice'] : [];

        return new ExternalProductData(
            providerCode: ProviderCode::YandexMarket->value,
            externalProductId: $marketSku ?? $offerId,
            externalOfferId: $offerId,
            title: $this->title($offer, $mapping),
            brand: $this->stringOrNull($offer['vendor'] ?? null),
            description: $this->stringOrNull($offer['description'] ?? null),
            priceAmount: $this->minorUnits($price['value'] ?? null),
            oldPriceAmount: $this->minorUnits($price['discountBase'] ?? null),
            currency: $this->currency($price),
            categoryExternalId: $this->stringOrNull(
                $offer['category'] ?? $offer['marketCategoryId'] ?? $mapping['marketCategoryId'] ?? null
            ),
            categoryName: $this->stringOrNull($mapping['marketCategoryName'] ?? null),
            imageUrls: $this->imageUrls($offer),
            productUrl: $marketSku !== null ? self::PRODUCT_BASE_URL . $marketSku : null,
            availabilityStatus: $this->availability($offer),
            stockQuantity: null, // Offer mappings carry no stock; warehouse stocks live on a separate endpoint.
            ratingValue: null,
            ratingCount: null,
            rawPayload: $yandexOffer,
        );
    }

    /**
     * @param array<string, mixed> $offer
     * @param array<string, mixed> $mapping
     */
    private function title(array $offer, array $mapping): string
    {
        $title = $this->stringOrNull($offer['name'] ?? null)
            ?? $this->stringOrNull($mapping['marketSkuName'] ?? null);

        return $title ?? '';
    }

    /**
     * Prices arrive as numbers or numeric strings in major units.
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
     * @param array<string, mixed> $price
     */
    private function currency(array $price): string
    {
        $currency = trim((string) ($price['currencyId'] ?? ''));

        return $currency !== '' ? mb_strtoupper($currency) : 'RUB';
    }

    /**
     * @param array<string, mixed> $offer
     * @return string[]
     */
    private function imageUrls(array $offer): array
    {
        $pictures = $offer['pictures'] ?? null;

        if (! is_array($pictures)) {
            return [];
        }

        $urls = [];

        foreach ($pictures as $picture) {
            $url = is_array($picture)
                ? $this->stringOrNull($picture['url'] ?? null)
                : $this->stringOrNull($picture);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function availability(array $offer): string
    {
        if (($offer['archived'] ?? false) === true) {
            return Availability::Archived->value;
        }

        // Without a stocks call the sellability of an offer is unknown; the
        // card status only tells us how far moderation got.
        return Availability::Unknown->value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
