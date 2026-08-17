<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchQuery;
use App\Enums\Availability;
use App\Enums\ProviderCode;
use App\Services\Providers\YandexMarket\YandexMarketProductMapper;
use PHPUnit\Framework\TestCase;

class YandexMarketProductMapperTest extends TestCase
{
    private YandexMarketProductMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new YandexMarketProductMapper();
    }

    public function test_maps_complete_item_correctly(): void
    {
        $raw = [
            'external_id' => '1456789012',
            'title' => 'Смартфон Samsung Galaxy A54 5G 8/128 ГБ, чёрный',
            'brand' => 'Samsung',
            'price_amount' => 2899900,
            'old_price_amount' => 3499900,
            'currency' => 'RUB',
            'image_url' => 'https://avatars.mds.yandex.net/get-mpic/12345/img_id_samsung_a54/orig',
            'product_url' => 'https://market.yandex.ru/product/1456789012',
            'rating_value' => 4.7,
            'rating_count' => 1823,
            'availability_status' => 'in_stock',
            'stock_quantity' => 42,
            'raw_payload' => ['delivery_info' => 'Доставка завтра'],
        ];

        $query = new ProductSearchQuery(text: 'samsung galaxy');
        $items = $this->mapper->mapMany([$raw], $query);

        $this->assertCount(1, $items);

        $item = $items[0];
        $this->assertInstanceOf(ExternalProductData::class, $item);
        $this->assertSame(ProviderCode::YandexMarket->value, $item->providerCode);
        $this->assertSame('1456789012', $item->externalProductId);
        $this->assertNull($item->externalOfferId);
        $this->assertSame('Смартфон Samsung Galaxy A54 5G 8/128 ГБ, чёрный', $item->title);
        $this->assertSame('Samsung', $item->brand);
        $this->assertSame(2899900, $item->priceAmount);
        $this->assertSame(3499900, $item->oldPriceAmount);
        $this->assertSame('RUB', $item->currency);
        $this->assertSame(['https://avatars.mds.yandex.net/get-mpic/12345/img_id_samsung_a54/orig'], $item->imageUrls);
        $this->assertSame('https://market.yandex.ru/product/1456789012', $item->productUrl);
        $this->assertSame(4.7, $item->ratingValue);
        $this->assertSame(1823, $item->ratingCount);
        $this->assertSame(Availability::InStock->value, $item->availabilityStatus);
        $this->assertSame(42, $item->stockQuantity);
    }

    public function test_skips_items_with_empty_title(): void
    {
        $raw = [
            'external_id' => '1234',
            'title' => '',
            'brand' => 'Test',
            'price_amount' => 10000,
            'product_url' => 'https://market.yandex.ru/product/1234',
        ];

        $query = new ProductSearchQuery(text: 'test');
        $items = $this->mapper->mapMany([$raw], $query);

        $this->assertCount(0, $items);
    }

    public function test_generates_fallback_external_id_from_product_url(): void
    {
        $raw = [
            'external_id' => '',
            'title' => 'Товар без внешнего ID',
            'brand' => null,
            'price_amount' => 50000,
            'product_url' => 'https://market.yandex.ru/product/some-slug',
        ];

        $query = new ProductSearchQuery(text: 'товар');
        $items = $this->mapper->mapMany([$raw], $query);

        $this->assertCount(1, $items);
        $this->assertStringStartsWith('yandex:', $items[0]->externalProductId);
        $this->assertSame('yandex:' . md5('https://market.yandex.ru/product/some-slug'), $items[0]->externalProductId);
    }

    public function test_maps_many_items_from_fixture(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/yandex_market/scrape_response.json'),
            true,
        );

        $query = new ProductSearchQuery(text: 'electronics');
        $items = $this->mapper->mapMany($fixture['items'], $query);

        $this->assertCount(5, $items);

        // First item has old_price (discount)
        $this->assertSame(3499900, $items[0]->oldPriceAmount);

        // Second item has no rating
        $this->assertNull($items[1]->ratingValue);
        $this->assertNull($items[1]->ratingCount);

        // Third item has availability in_stock
        $this->assertSame(Availability::InStock->value, $items[2]->availabilityStatus);

        // Fourth item has out_of_stock
        $this->assertSame(Availability::OutOfStock->value, $items[3]->availabilityStatus);

        // All are ExternalProductData
        foreach ($items as $item) {
            $this->assertInstanceOf(ExternalProductData::class, $item);
            $this->assertSame(ProviderCode::YandexMarket->value, $item->providerCode);
        }
    }
}
