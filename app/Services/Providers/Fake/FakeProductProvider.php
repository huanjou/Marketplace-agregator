<?php

declare(strict_types=1);

namespace App\Services\Providers\Fake;

use App\Contracts\ProductProviderInterface;
use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Marketplace\ProviderCapabilityData;
use App\DTO\Marketplace\ProviderHealthData;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Enums\Availability;
use App\Enums\ProviderCode;
use App\Enums\SearchSortField;

/**
 * In-memory provider backed by a static demo catalogue.
 *
 * Used for local development, tests and demos so the aggregation pipeline can
 * be exercised end-to-end without any external marketplace credentials.
 * All monetary amounts are expressed in minor units (kopecks).
 */
class FakeProductProvider implements ProductProviderInterface
{
    private const IMAGE_BASE_URL = 'https://placehold.co/400x400';

    private const PRODUCT_BASE_URL = 'https://demo.marketplace.test/product';

    /** @var ExternalProductData[]|null */
    private ?array $catalogue = null;

    public function code(): string
    {
        return ProviderCode::Fake->value;
    }

    public function displayName(): string
    {
        return (string) config('marketplace.providers.fake.display_name', 'Demo Provider');
    }

    public function capabilities(): ProviderCapabilityData
    {
        return new ProviderCapabilityData(
            supportedFilters: [
                'price_range',
                'brand',
                'category',
                'rating',
                'availability',
            ],
            supportedSorts: [
                SearchSortField::Relevance->value,
                SearchSortField::PriceAsc->value,
                SearchSortField::PriceDesc->value,
                SearchSortField::RatingDesc->value,
            ],
            supportsPagination: true,
            maxResultsPerPage: 100,
            supportsRealtimeSearch: true,
        );
    }

    public function isEnabled(): bool
    {
        return (bool) config('marketplace.providers.fake.enabled', false);
    }

    /**
     * Returns the FULL match set for the query — the page/perPage carried by
     * the query are advisory only. Slicing is ResultAggregator's job, because
     * only it sees the merged, globally sorted list across every provider.
     */
    public function search(ProductSearchQuery $query): ProductSearchResult
    {
        $startedAt = microtime(true);
        $normalized = $query->normalized();

        $matched = $this->applyFilters($this->products(), $normalized);
        $matched = $this->applySort($matched, $normalized);
        $matched = array_values($matched);

        $total = count($matched);

        return new ProductSearchResult(
            items: $matched,
            total: $total,
            nextCursor: null,
            providerMeta: [
                $this->code() => [
                    'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    // Echoed for observability only — not applied to the result.
                    'page' => $normalized->page,
                    'per_page' => $normalized->perPage,
                    'returned' => $total,
                    'total' => $total,
                ],
            ],
        );
    }

    public function fetchByExternalId(string $externalId): ?ExternalProductData
    {
        foreach ($this->products() as $product) {
            if ($product->externalProductId === $externalId) {
                return $product;
            }
        }

        return null;
    }

    public function healthCheck(): ProviderHealthData
    {
        return new ProviderHealthData(
            providerCode: $this->code(),
            status: 'healthy',
            responseTimeMs: 1,
            message: sprintf('Demo catalogue with %d products.', count($this->products())),
            checkedAt: now()->toDateTimeImmutable(),
        );
    }

    /**
     * @param ExternalProductData[] $products
     * @return ExternalProductData[]
     */
    private function applyFilters(array $products, ProductSearchQuery $query): array
    {
        $filters = $query->filters;
        $text = $query->text;

        return array_values(array_filter($products, function (ExternalProductData $product) use ($filters, $text): bool {
            if ($text !== '' && $this->relevanceScore($product, $text) === 0) {
                return false;
            }

            if ($filters->minPriceAmount !== null
                && ($product->priceAmount === null || $product->priceAmount < $filters->minPriceAmount)) {
                return false;
            }

            if ($filters->maxPriceAmount !== null
                && ($product->priceAmount === null || $product->priceAmount > $filters->maxPriceAmount)) {
                return false;
            }

            if ($filters->currency !== null && $product->currency !== $filters->currency) {
                return false;
            }

            if (! empty($filters->brands) && ! $this->matchesAny($product->brand, $filters->brands)) {
                return false;
            }

            if (! empty($filters->externalCategoryIds)
                && ! in_array($product->categoryExternalId, $filters->externalCategoryIds, true)) {
                return false;
            }

            if ($filters->minRating !== null
                && ($product->ratingValue === null || $product->ratingValue < $filters->minRating)) {
                return false;
            }

            if ($filters->availability !== null && $product->availabilityStatus !== $filters->availability) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param ExternalProductData[] $products
     * @return ExternalProductData[]
     */
    private function applySort(array $products, ProductSearchQuery $query): array
    {
        $text = $query->text;

        $comparator = match ($query->sort->field) {
            SearchSortField::PriceAsc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => ($a->priceAmount ?? PHP_INT_MAX) <=> ($b->priceAmount ?? PHP_INT_MAX),
            SearchSortField::PriceDesc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => ($b->priceAmount ?? PHP_INT_MIN) <=> ($a->priceAmount ?? PHP_INT_MIN),
            SearchSortField::RatingDesc => static fn (ExternalProductData $a, ExternalProductData $b): int
                => [$b->ratingValue ?? 0.0, $b->ratingCount ?? 0] <=> [$a->ratingValue ?? 0.0, $a->ratingCount ?? 0],
            SearchSortField::Newest => static fn (ExternalProductData $a, ExternalProductData $b): int
                => strcmp((string) $b->externalProductId, (string) $a->externalProductId),
            SearchSortField::Relevance => fn (ExternalProductData $a, ExternalProductData $b): int
                => [$this->relevanceScore($b, $text), $b->ratingValue ?? 0.0]
                <=> [$this->relevanceScore($a, $text), $a->ratingValue ?? 0.0],
        };

        usort($products, $comparator);

        return $products;
    }

    /**
     * Crude relevance heuristic: exact title hits outrank prefix hits, which in
     * turn outrank substring and brand hits. Returns 0 when nothing matches.
     */
    private function relevanceScore(ExternalProductData $product, string $text): int
    {
        if ($text === '') {
            return 1;
        }

        $needle = mb_strtolower(trim($text));
        $title = mb_strtolower($product->title);
        $brand = mb_strtolower((string) $product->brand);

        if ($title === $needle) {
            return 100;
        }

        $score = 0;

        if (str_starts_with($title, $needle)) {
            $score = 80;
        } elseif (str_contains($title, $needle)) {
            $score = 60;
        }

        if ($brand !== '' && $brand === $needle) {
            $score = max($score, 70);
        } elseif ($brand !== '' && str_contains($brand, $needle)) {
            $score = max($score, 40);
        }

        return $score;
    }

    /**
     * @param string[] $candidates
     */
    private function matchesAny(?string $value, array $candidates): bool
    {
        if ($value === null) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if (mb_strtolower($value) === mb_strtolower((string) $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ExternalProductData[]
     */
    private function products(): array
    {
        return $this->catalogue ??= array_map(
            fn (array $row): ExternalProductData => $this->makeProduct($row),
            $this->mockRows()
        );
    }

    /**
     * @param array{
     *     id: string,
     *     title: string,
     *     brand: string,
     *     description: string,
     *     price: int,
     *     old_price?: int|null,
     *     category: string,
     *     category_id: string,
     *     stock: int,
     *     rating: float,
     *     rating_count: int
     * } $row
     */
    private function makeProduct(array $row): ExternalProductData
    {
        $stock = $row['stock'];

        return new ExternalProductData(
            providerCode: $this->code(),
            externalProductId: $row['id'],
            externalOfferId: $row['id'] . '-offer',
            title: $row['title'],
            brand: $row['brand'],
            description: $row['description'],
            priceAmount: $row['price'],
            oldPriceAmount: $row['old_price'] ?? null,
            currency: 'RUB',
            categoryExternalId: $row['category_id'],
            categoryName: $row['category'],
            imageUrls: [
                self::IMAGE_BASE_URL . '?text=' . rawurlencode($row['title']),
                self::IMAGE_BASE_URL . '?text=' . rawurlencode($row['title'] . ' 2'),
            ],
            productUrl: self::PRODUCT_BASE_URL . '/' . $row['id'],
            availabilityStatus: $stock > 0
                ? Availability::InStock->value
                : Availability::OutOfStock->value,
            stockQuantity: $stock,
            ratingValue: $row['rating'],
            ratingCount: $row['rating_count'],
            rawPayload: [
                'source' => 'fake-catalogue',
                'category_slug' => $row['category_id'],
            ],
        );
    }

    /**
     * Static demo catalogue. Prices are in kopecks (1 RUB = 100 kopecks).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mockRows(): array
    {
        return [
            // --- Electronics: laptops -------------------------------------
            [
                'id' => 'fake-001', 'title' => 'Ноутбук ProBook 14 Ultra', 'brand' => 'Lenovo',
                'description' => 'Лёгкий ультрабук 14" с матовым IPS-экраном, 16 ГБ ОЗУ и SSD 512 ГБ.',
                'price' => 8990000, 'old_price' => 10490000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 12, 'rating' => 4.7, 'rating_count' => 1842,
            ],
            [
                'id' => 'fake-002', 'title' => 'Ноутбук GameForce 16 RTX', 'brand' => 'ASUS',
                'description' => 'Игровой ноутбук с дискретной графикой, экраном 165 Гц и системой охлаждения на 4 трубки.',
                'price' => 14990000, 'old_price' => null,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 4, 'rating' => 4.5, 'rating_count' => 623,
            ],
            [
                'id' => 'fake-003', 'title' => 'Ноутбук AirLite 13 M-series', 'brand' => 'Apple',
                'description' => 'Безвентиляторный ноутбук в алюминиевом корпусе, до 18 часов автономной работы.',
                'price' => 12450000, 'old_price' => 13990000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 7, 'rating' => 4.9, 'rating_count' => 3105,
            ],
            [
                'id' => 'fake-004', 'title' => 'Ноутбук OfficeLine 15', 'brand' => 'Acer',
                'description' => 'Бюджетный ноутбук для учёбы и офисных задач, 8 ГБ ОЗУ, SSD 256 ГБ.',
                'price' => 4590000, 'old_price' => 5290000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 25, 'rating' => 4.1, 'rating_count' => 918,
            ],

            // --- Electronics: phones -------------------------------------
            [
                'id' => 'fake-005', 'title' => 'Смартфон Pulse 12 Pro 256GB', 'brand' => 'Samsung',
                'description' => 'Флагман с AMOLED 6.7", тройной камерой 108 Мп и быстрой зарядкой 65 Вт.',
                'price' => 9990000, 'old_price' => 11990000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 18, 'rating' => 4.6, 'rating_count' => 5420,
            ],
            [
                'id' => 'fake-006', 'title' => 'Смартфон Nova Lite 128GB', 'brand' => 'Xiaomi',
                'description' => 'Доступный смартфон с батареей 5000 мА·ч и экраном 90 Гц.',
                'price' => 1890000, 'old_price' => 2290000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 63, 'rating' => 4.3, 'rating_count' => 7731,
            ],
            [
                'id' => 'fake-007', 'title' => 'Смартфон Titan Max 512GB', 'brand' => 'Apple',
                'description' => 'Титановый корпус, чип последнего поколения, съёмка видео в 4K 60 fps.',
                'price' => 14990000, 'old_price' => null,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 3, 'rating' => 4.8, 'rating_count' => 2280,
            ],
            [
                'id' => 'fake-008', 'title' => 'Смартфон Basic 5 64GB', 'brand' => 'Realme',
                'description' => 'Простой смартфон для звонков и мессенджеров, две SIM-карты.',
                'price' => 890000, 'old_price' => null,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 0, 'rating' => 3.8, 'rating_count' => 412,
            ],

            // --- Electronics: headphones ---------------------------------
            [
                'id' => 'fake-009', 'title' => 'Наушники SilentWave ANC', 'brand' => 'Sony',
                'description' => 'Накладные беспроводные наушники с активным шумоподавлением и 30 ч работы.',
                'price' => 2490000, 'old_price' => 2990000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 31, 'rating' => 4.7, 'rating_count' => 4190,
            ],
            [
                'id' => 'fake-010', 'title' => 'Наушники AirBuds Mini', 'brand' => 'Samsung',
                'description' => 'Компактные TWS-наушники с кейсом-зарядкой и защитой IPX4.',
                'price' => 649000, 'old_price' => 899000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 84, 'rating' => 4.2, 'rating_count' => 6015,
            ],
            [
                'id' => 'fake-011', 'title' => 'Наушники StudioMonitor 80', 'brand' => 'Audio-Technica',
                'description' => 'Проводные студийные наушники закрытого типа для мониторинга и микширования.',
                'price' => 1750000, 'old_price' => null,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 9, 'rating' => 4.9, 'rating_count' => 733,
            ],
            [
                'id' => 'fake-012', 'title' => 'Наушники SportRun Bone', 'brand' => 'Shokz',
                'description' => 'Наушники с костной проводимостью для бега, вес 29 г.',
                'price' => 1290000, 'old_price' => 1590000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 16, 'rating' => 4.4, 'rating_count' => 1104,
            ],

            // --- Electronics: tablets ------------------------------------
            [
                'id' => 'fake-013', 'title' => 'Планшет Canvas Pad 11', 'brand' => 'Apple',
                'description' => 'Планшет 11" с поддержкой стилуса и ламинированным экраном 120 Гц.',
                'price' => 7890000, 'old_price' => 8490000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 11, 'rating' => 4.8, 'rating_count' => 1966,
            ],
            [
                'id' => 'fake-014', 'title' => 'Планшет FamilyTab 10 LTE', 'brand' => 'Lenovo',
                'description' => 'Семейный планшет с 4G, режимом чтения и родительским контролем.',
                'price' => 2190000, 'old_price' => 2590000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 44, 'rating' => 4.0, 'rating_count' => 2571,
            ],
            [
                'id' => 'fake-015', 'title' => 'Планшет InkReader 7', 'brand' => 'Onyx',
                'description' => 'Электронная книга с экраном E-Ink 7", подсветкой и словарями.',
                'price' => 1990000, 'old_price' => null,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 6, 'rating' => 4.6, 'rating_count' => 488,
            ],
            [
                'id' => 'fake-016', 'title' => 'Монитор ClearView 27 4K', 'brand' => 'Dell',
                'description' => 'Монитор 27" IPS 4K с USB-C докингом и заводской калибровкой.',
                'price' => 5490000, 'old_price' => 6290000,
                'category' => 'Электроника', 'category_id' => 'electronics',
                'stock' => 14, 'rating' => 4.5, 'rating_count' => 1327,
            ],

            // --- Clothing: jackets ---------------------------------------
            [
                'id' => 'fake-017', 'title' => 'Куртка зимняя NordPeak Parka', 'brand' => 'Columbia',
                'description' => 'Тёплая парка с мембраной, капюшоном на меху и утеплителем до −30 °C.',
                'price' => 1890000, 'old_price' => 2490000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 22, 'rating' => 4.6, 'rating_count' => 861,
            ],
            [
                'id' => 'fake-018', 'title' => 'Куртка-ветровка CityRun', 'brand' => 'Nike',
                'description' => 'Лёгкая ветровка с водоотталкивающим покрытием и светоотражающими вставками.',
                'price' => 690000, 'old_price' => 890000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 57, 'rating' => 4.3, 'rating_count' => 1503,
            ],
            [
                'id' => 'fake-019', 'title' => 'Куртка кожаная Rider Classic', 'brand' => 'Levis',
                'description' => 'Косуха из натуральной кожи с подкладкой и косыми карманами.',
                'price' => 2790000, 'old_price' => null,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 8, 'rating' => 4.7, 'rating_count' => 344,
            ],
            [
                'id' => 'fake-020', 'title' => 'Куртка джинсовая Denim Loose', 'brand' => 'Levis',
                'description' => 'Свободная джинсовая куртка из плотного денима, унисекс.',
                'price' => 1090000, 'old_price' => 1390000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 0, 'rating' => 4.2, 'rating_count' => 692,
            ],

            // --- Clothing: sneakers --------------------------------------
            [
                'id' => 'fake-021', 'title' => 'Кроссовки AeroGlide 3', 'brand' => 'Nike',
                'description' => 'Беговые кроссовки с амортизирующей пеной и сетчатым верхом.',
                'price' => 1290000, 'old_price' => 1690000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 38, 'rating' => 4.5, 'rating_count' => 2914,
            ],
            [
                'id' => 'fake-022', 'title' => 'Кроссовки UrbanCourt Retro', 'brand' => 'Adidas',
                'description' => 'Классические кеды в ретро-стиле с кожаными накладками.',
                'price' => 990000, 'old_price' => null,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 45, 'rating' => 4.4, 'rating_count' => 1780,
            ],
            [
                'id' => 'fake-023', 'title' => 'Кроссовки TrailGrip GTX', 'brand' => 'Salomon',
                'description' => 'Трейловые кроссовки с мембраной Gore-Tex и агрессивным протектором.',
                'price' => 1750000, 'old_price' => 1990000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 13, 'rating' => 4.8, 'rating_count' => 507,
            ],
            [
                'id' => 'fake-024', 'title' => 'Кроссовки DailyStep Basic', 'brand' => 'Puma',
                'description' => 'Универсальные кроссовки на каждый день, съёмная стелька.',
                'price' => 490000, 'old_price' => 650000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 96, 'rating' => 3.9, 'rating_count' => 3266,
            ],

            // --- Clothing: t-shirts --------------------------------------
            [
                'id' => 'fake-025', 'title' => 'Футболка Cotton Essential', 'brand' => 'Uniqlo',
                'description' => 'Базовая футболка из плотного хлопка, плотность 180 г/м².',
                'price' => 129000, 'old_price' => 179000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 240, 'rating' => 4.5, 'rating_count' => 8420,
            ],
            [
                'id' => 'fake-026', 'title' => 'Футболка Oversize Print', 'brand' => 'Zara',
                'description' => 'Футболка оверсайз с крупным принтом и спущенным плечом.',
                'price' => 199000, 'old_price' => null,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 74, 'rating' => 4.1, 'rating_count' => 1192,
            ],
            [
                'id' => 'fake-027', 'title' => 'Футболка спортивная DryFit', 'brand' => 'Adidas',
                'description' => 'Тренировочная футболка из влагоотводящей ткани с сетчатыми зонами.',
                'price' => 249000, 'old_price' => 320000,
                'category' => 'Одежда', 'category_id' => 'clothing',
                'stock' => 61, 'rating' => 4.6, 'rating_count' => 2038,
            ],

            // --- Books ----------------------------------------------------
            [
                'id' => 'fake-028', 'title' => 'Тени над Аквилоном. Роман', 'brand' => 'АСТ',
                'description' => 'Фантастический роман о городе, который просыпается раз в сто лет. Твёрдый переплёт.',
                'price' => 89000, 'old_price' => 112000,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 52, 'rating' => 4.4, 'rating_count' => 1204,
            ],
            [
                'id' => 'fake-029', 'title' => 'Дом у соленой воды', 'brand' => 'Эксмо',
                'description' => 'Семейная сага о трёх поколениях рыбаков северного побережья.',
                'price' => 74000, 'old_price' => null,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 33, 'rating' => 4.2, 'rating_count' => 638,
            ],
            [
                'id' => 'fake-030', 'title' => 'Чистая архитектура приложений', 'brand' => 'Питер',
                'description' => 'Практическое руководство по слоистой архитектуре, границам модулей и тестируемости.',
                'price' => 189000, 'old_price' => 245000,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 27, 'rating' => 4.8, 'rating_count' => 2411,
            ],
            [
                'id' => 'fake-031', 'title' => 'PostgreSQL изнутри. Издание 2-е', 'brand' => 'ДМК Пресс',
                'description' => 'Устройство планировщика, MVCC, индексов и репликации на практических примерах.',
                'price' => 249000, 'old_price' => null,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 19, 'rating' => 4.9, 'rating_count' => 907,
            ],
            [
                'id' => 'fake-032', 'title' => 'Паттерны проектирования на PHP', 'brand' => 'Питер',
                'description' => 'Каталог паттернов с примерами на современном PHP 8 и разбором антипаттернов.',
                'price' => 156000, 'old_price' => 198000,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 41, 'rating' => 4.5, 'rating_count' => 1338,
            ],
            [
                'id' => 'fake-033', 'title' => 'Космос: краткая история наблюдений', 'brand' => 'Альпина',
                'description' => 'Научно-популярная книга об истории астрономии от Галилея до радиотелескопов.',
                'price' => 118000, 'old_price' => null,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 0, 'rating' => 4.7, 'rating_count' => 1875,
            ],
            [
                'id' => 'fake-034', 'title' => 'Мозг и привычки', 'brand' => 'Альпина',
                'description' => 'Что нейробиология знает о формировании привычек и как это применять.',
                'price' => 96000, 'old_price' => 129000,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 88, 'rating' => 4.3, 'rating_count' => 3502,
            ],
            [
                'id' => 'fake-035', 'title' => 'Карманный справочник по химии', 'brand' => 'Эксмо',
                'description' => 'Компактный справочник формул и реакций для школьников и студентов.',
                'price' => 32000, 'old_price' => null,
                'category' => 'Книги', 'category_id' => 'books',
                'stock' => 150, 'rating' => 4.0, 'rating_count' => 421,
            ],

            // --- Home & Garden -------------------------------------------
            [
                'id' => 'fake-036', 'title' => 'Аккумуляторная дрель-шуруповёрт DriveMax 18V', 'brand' => 'Bosch',
                'description' => 'Шуруповёрт 18 В с двумя аккумуляторами, кейсом и набором бит.',
                'price' => 1290000, 'old_price' => 1590000,
                'category' => 'Дом и сад', 'category_id' => 'home-garden',
                'stock' => 23, 'rating' => 4.7, 'rating_count' => 2094,
            ],
            [
                'id' => 'fake-037', 'title' => 'Набор инструментов ToolCase 108', 'brand' => 'Stanley',
                'description' => 'Универсальный набор из 108 предметов в ударопрочном кейсе.',
                'price' => 749000, 'old_price' => 990000,
                'category' => 'Дом и сад', 'category_id' => 'home-garden',
                'stock' => 35, 'rating' => 4.4, 'rating_count' => 1216,
            ],
            [
                'id' => 'fake-038', 'title' => 'Газонокосилка электрическая GreenCut 1600', 'brand' => 'Makita',
                'description' => 'Электрическая газонокосилка 1600 Вт с травосборником 40 л.',
                'price' => 4790000, 'old_price' => null,
                'category' => 'Дом и сад', 'category_id' => 'home-garden',
                'stock' => 5, 'rating' => 4.5, 'rating_count' => 318,
            ],
            [
                'id' => 'fake-039', 'title' => 'Светильник настольный WarmGlow', 'brand' => 'IKEA',
                'description' => 'Настольная лампа с тёплым светом, латунным основанием и тканевым абажуром.',
                'price' => 289000, 'old_price' => 349000,
                'category' => 'Дом и сад', 'category_id' => 'home-garden',
                'stock' => 67, 'rating' => 4.2, 'rating_count' => 1441,
            ],
            [
                'id' => 'fake-040', 'title' => 'Ваза керамическая StoneLine 30 см', 'brand' => 'IKEA',
                'description' => 'Керамическая ваза ручной формовки с матовой глазурью каменного оттенка.',
                'price' => 159000, 'old_price' => null,
                'category' => 'Дом и сад', 'category_id' => 'home-garden',
                'stock' => 42, 'rating' => 4.1, 'rating_count' => 276,
            ],
            [
                'id' => 'fake-041', 'title' => 'Плед вязаный SoftKnit 200x150', 'brand' => 'Zara Home',
                'description' => 'Крупной вязки плед из смеси хлопка и акрила, машинная стирка.',
                'price' => 349000, 'old_price' => 449000,
                'category' => 'Дом и сад', 'category_id' => 'home-garden',
                'stock' => 58, 'rating' => 4.6, 'rating_count' => 987,
            ],
            [
                'id' => 'fake-042', 'title' => 'Секатор садовый SharpCut Pro', 'brand' => 'Fiskars',
                'description' => 'Садовый секатор с закалёнными лезвиями и амортизатором рукояти.',
                'price' => 219000, 'old_price' => null,
                'category' => 'Дом и сад', 'category_id' => 'home-garden',
                'stock' => 74, 'rating' => 4.8, 'rating_count' => 1653,
            ],
        ];
    }
}
