<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Marketplace\ExternalProductData;
use App\Models\Provider;
use App\Models\ProviderProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists provider offers into the provider_products table.
 *
 * v1 deliberately stops at the provider layer: no canonical Product rows are
 * created (product_id stays null) because reliable cross-provider matching
 * needs identifiers (EAN/MPN) that we do not have yet. Linking can be done
 * later without touching this class.
 */
class ProductResultNormalizer
{
    /**
     * provider_code => providers.id, memoised per call to avoid N+1 lookups.
     *
     * @var array<string, int|null>
     */
    private array $providerIds = [];

    /**
     * @param ExternalProductData[] $items
     */
    public function persist(array $items): void
    {
        if ($items === []) {
            return;
        }

        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                if (! $item instanceof ExternalProductData) {
                    continue;
                }

                $providerId = $this->providerId($item->providerCode);

                if ($providerId === null) {
                    // Provider not registered in the DB yet — skip the offer
                    // rather than aborting the whole batch.
                    Log::warning('Skipping offer for unknown provider code.', [
                        'provider_code' => $item->providerCode,
                        'external_product_id' => $item->externalProductId,
                    ]);

                    continue;
                }

                ProviderProduct::query()->updateOrCreate(
                    [
                        'provider_id' => $providerId,
                        'external_product_id' => $item->externalProductId,
                        'external_offer_id' => $item->externalOfferId,
                    ],
                    [
                        'provider_code' => $item->providerCode,
                        'external_category_id' => $item->categoryExternalId,
                        'external_url' => $item->productUrl,
                        'title' => mb_substr($item->title, 0, 512),
                        'brand' => $item->brand !== null ? mb_substr($item->brand, 0, 255) : null,
                        'price_amount' => $item->priceAmount,
                        'old_price_amount' => $item->oldPriceAmount,
                        'currency' => $item->currency,
                        'availability_status' => $item->availabilityStatus,
                        'stock_quantity' => $item->stockQuantity,
                        'rating_value' => $item->ratingValue,
                        'rating_count' => $item->ratingCount,
                        'image_urls' => $item->imageUrls,
                        'raw_payload' => $item->rawPayload,
                        'last_synced_at' => CarbonImmutable::now(),
                    ]
                );
            }
        });
    }

    private function providerId(string $code): ?int
    {
        if (array_key_exists($code, $this->providerIds)) {
            return $this->providerIds[$code];
        }

        $id = Provider::query()->where('code', $code)->value('id');

        return $this->providerIds[$code] = $id !== null ? (int) $id : null;
    }
}
