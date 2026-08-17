<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\Enums\Availability;
use App\Services\Providers\Ozon\OzonProductMapper;
use PHPUnit\Framework\TestCase;

/**
 * The mapper is pure — no facades, no container — so it runs on the bare
 * PHPUnit case against the recorded Playwright payload.
 */
class OzonProductMapperTest extends TestCase
{
    private OzonProductMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new OzonProductMapper();
    }

    public function test_map_many_converts_all_valid_items(): void
    {
        $result = $this->mapper->mapMany($this->fixtureItems(), $this->query());

        $this->assertCount(5, $result);

        $first = $result[0];

        $this->assertInstanceOf(ExternalProductData::class, $first);
        $this->assertSame('ozon', $first->providerCode);
        $this->assertSame('1512345678', $first->externalProductId);
        $this->assertNull($first->externalOfferId);
        $this->assertSame('ARDOR GAMING', $first->brand);
        $this->assertSame('RUB', $first->currency);
        $this->assertIsInt($first->priceAmount);
        $this->assertStringStartsWith('https://www.ozon.ru/product/', (string) $first->productUrl);
        $this->assertSame(
            ['https://cdn1.ozone.ru/s3/multimedia-1-t/7012345678.jpg'],
            $first->imageUrls
        );
        $this->assertSame(Availability::InStock->value, $first->availabilityStatus);
        $this->assertSame(4.8, $first->ratingValue);
        $this->assertSame(431, $first->ratingCount);
        // The tile carried its own source snippet, so it is preserved as-is.
        $this->assertSame(0, $first->rawPayload['tile_index']);
    }

    public function test_map_many_skips_items_with_empty_title(): void
    {
        $items = $this->fixtureItems();
        $baseline = count($this->mapper->mapMany($items, $this->query()));

        // A banner/promo slot: the scraper emits the tile but there is no name.
        $items[] = [
            'external_id' => '9999999999',
            'title' => '   ',
            'price_amount' => 1990000,
            'product_url' => 'https://www.ozon.ru/product/promo-9999999999/',
        ];

        $result = $this->mapper->mapMany($items, $this->query());

        $this->assertCount($baseline, $result);
        $this->assertCount(count($items) - 1, $result);
    }

    public function test_price_amount_is_integer_kopecks(): void
    {
        $result = $this->mapper->mapMany($this->fixtureItems(), $this->query());

        $this->assertIsInt($result[0]->priceAmount);
        $this->assertSame(8499000, $result[0]->priceAmount);
        $this->assertSame('84 990.00 RUB', $result[0]->priceFormatted());
    }

    public function test_old_price_preserved_when_present(): void
    {
        $result = $this->mapper->mapMany($this->fixtureItems(), $this->query());

        $discounted = array_values(array_filter(
            $result,
            static fn (ExternalProductData $item): bool => $item->oldPriceAmount !== null
        ));

        $this->assertCount(1, $discounted);
        $this->assertSame(10299000, $discounted[0]->oldPriceAmount);
        $this->assertSame(8499000, $discounted[0]->priceAmount);
        $this->assertLessThan($discounted[0]->oldPriceAmount, $discounted[0]->priceAmount);
    }

    public function test_missing_rating_and_out_of_stock_are_mapped_faithfully(): void
    {
        $result = $this->mapper->mapMany($this->fixtureItems(), $this->query());

        $withoutRating = $this->itemById($result, '1401002233');
        $this->assertNull($withoutRating->ratingValue);
        $this->assertNull($withoutRating->ratingCount);

        $soldOut = $this->itemById($result, '1755000111');
        $this->assertSame(Availability::OutOfStock->value, $soldOut->availabilityStatus);
        $this->assertSame(0, $soldOut->stockQuantity);
    }

    public function test_items_without_an_external_id_get_a_stable_fallback(): void
    {
        $raw = [
            'external_id' => '',
            'title' => 'Компьютер без идентификатора',
            'product_url' => 'https://www.ozon.ru/product/kompyuter-bez-identifikatora/',
        ];

        $first = $this->mapper->mapMany([$raw], $this->query())[0];
        $second = $this->mapper->mapMany([$raw], $this->query())[0];

        $this->assertSame('ozon:' . md5($raw['product_url']), $first->externalProductId);
        $this->assertSame($first->externalProductId, $second->externalProductId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixtureItems(): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/ozon/scrape_response.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        return $payload['items'];
    }

    /**
     * @param ExternalProductData[] $items
     */
    private function itemById(array $items, string $externalProductId): ExternalProductData
    {
        foreach ($items as $item) {
            if ($item->externalProductId === $externalProductId) {
                return $item;
            }
        }

        $this->fail(sprintf('No mapped item with external id [%s].', $externalProductId));
    }

    private function query(): ProductSearchQuery
    {
        return new ProductSearchQuery(text: 'компьютер', page: 1, perPage: 20, providerCodes: ['ozon']);
    }
}
