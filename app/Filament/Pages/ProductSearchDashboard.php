<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Enums\ProviderCode;
use App\Enums\SearchSortField;
use App\Filament\Widgets\ProviderStatusWidget;
use App\Filament\Widgets\SearchStatsWidget;
use App\Services\ProductSearchService;
use App\Services\ProviderRegistry;
use Filament\Actions;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Panel home page: a live console over ProductSearchService.
 *
 * Everything the user types is translated into the search DTOs once, in
 * search(), and the aggregated result is flattened into plain arrays so the
 * Blade view never touches the readonly DTOs (and Livewire can serialise the
 * page state between requests).
 */
class ProductSearchDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Search';

    protected static ?int $navigationSort = -100;

    protected static ?string $slug = '/';

    protected static ?string $title = 'Product Search';

    protected static string $view = 'filament.pages.product-search-dashboard';

    /** Free text query. */
    public ?string $query = null;

    /** @var string[]|null Provider codes to fan out to. */
    public ?array $providerCodes = null;

    /** Price bounds are entered in whole roubles and converted to kopecks. */
    public ?int $minPrice = null;

    public ?int $maxPrice = null;

    /** @var string[]|null */
    public ?array $brands = null;

    public ?string $availability = null;

    public ?float $minRating = null;

    public ?string $sort = null;

    public ?int $perPage = null;

    public int $page = 1;

    /** @var array<int, array<string, mixed>>|null Flattened result rows, null until the first search. */
    public ?array $results = null;

    public ?int $total = null;

    public ?int $lastSearchMs = null;

    public ?bool $cacheHit = null;

    /** @var array<string, string>|null provider code => error message. */
    public ?array $errors = null;

    public function mount(): void
    {
        $this->form->fill([
            'query' => null,
            'providerCodes' => [ProviderCode::Ozon->value, ProviderCode::YandexMarket->value],
            'minPrice' => null,
            'maxPrice' => null,
            'brands' => [],
            'availability' => null,
            'minRating' => null,
            'sort' => SearchSortField::Relevance->value,
            'perPage' => 20,
        ]);

        // Land on a populated board instead of an empty one.
        $this->search();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(['default' => 1, 'lg' => 4])->schema([
                TextInput::make('query')
                    ->label('Search')
                    ->placeholder('e.g. laptop, книга, headphones')
                    ->autofocus()
                    ->autocomplete(false)
                    ->columnSpan(['default' => 1, 'lg' => 3]),
                Select::make('providerCodes')
                    ->label('Marketplaces')
                    ->multiple()
                    ->options(fn (): array => $this->providerOptions())
                    ->placeholder('All enabled')
                    ->live()
                    ->columnSpan(1),
            ]),

            /*
            Section::make('Filters')
                ->description('Narrow the fan-out. Prices are in roubles.')
                ->icon('heroicon-o-adjustments-horizontal')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                        TextInput::make('minPrice')
                            ->label('Min price')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₽'),
                        TextInput::make('maxPrice')
                            ->label('Max price')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₽'),
                        Select::make('availability')
                            ->label('Availability')
                            ->options([
                                'in_stock' => 'In stock',
                                'out_of_stock' => 'Out of stock',
                            ])
                            ->placeholder('Any'),
                        Select::make('minRating')
                            ->label('Minimum rating')
                            ->options([
                                '3' => '3.0+',
                                '4' => '4.0+',
                                '4.5' => '4.5+',
                            ])
                            ->placeholder('Any'),
                    ]),
                    Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                        TagsInput::make('brands')
                            ->label('Brands')
                            ->placeholder('add brand and press enter')
                            ->columnSpan(['default' => 1, 'lg' => 2]),
                        Select::make('sort')
                            ->label('Sort by')
                            ->options(self::sortOptions())
                            ->default(SearchSortField::Relevance->value)
                            ->selectablePlaceholder(false),
                        Select::make('perPage')
                            ->label('Per page')
                            ->options([10 => 10, 20 => 20, 50 => 50, 100 => 100])
                            ->default(20)
                            ->selectablePlaceholder(false),
                    ]),
                ]),
            */
        ]);
    }

    /**
     * @return Actions\Action[]
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('search')
                ->label('Search')
                ->icon('heroicon-m-magnifying-glass')
                ->keyBindings(['mod+enter'])
                ->action('search'),
            Actions\Action::make('reset')
                ->label('Reset')
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('gray')
                ->action('resetSearch'),
        ];
    }

    /**
     * @return class-string[]
     */
    protected function getHeaderWidgets(): array
    {
        return [
            ProviderStatusWidget::make([
                'selectedProviders' => $this->providerCodes,
            ]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    /**
     * @return class-string[]
     */
    protected function getFooterWidgets(): array
    {
        return [
            SearchStatsWidget::class,
        ];
    }

    /**
     * Run the aggregated search for the current form state.
     *
     * @param bool $resetPage false when the call comes from pagination, which
     *                        has already picked the page it wants.
     */
    public function search(bool $resetPage = true): void
    {
        $data = $this->form->getState();

        if ($resetPage) {
            $this->page = 1;
        }

        $filters = new ProductSearchFilters(
            minPriceAmount: $this->toKopecks($data['minPrice'] ?? null),
            maxPriceAmount: $this->toKopecks($data['maxPrice'] ?? null),
            brands: array_values(array_filter(
                array_map('trim', (array) ($data['brands'] ?? [])),
                static fn (string $brand): bool => $brand !== ''
            )),
            minRating: isset($data['minRating']) && $data['minRating'] !== null && $data['minRating'] !== ''
                ? (float) $data['minRating']
                : null,
            availability: ($data['availability'] ?? null) ?: null,
        );

        $sortField = SearchSortField::tryFrom((string) ($data['sort'] ?? '')) ?? SearchSortField::Relevance;

        $query = new ProductSearchQuery(
            text: (string) ($data['query'] ?? ''),
            filters: $filters,
            sort: new ProductSort(
                field: $sortField,
                direction: $sortField === SearchSortField::PriceAsc ? 'asc' : 'desc',
            ),
            page: max(1, $this->page),
            perPage: (int) ($data['perPage'] ?? 20),
            providerCodes: array_values((array) ($data['providerCodes'] ?? [])),
        );

        $startedAt = microtime(true);

        try {
            $result = app(ProductSearchService::class)->search($query);
        } catch (Throwable $e) {
            $this->lastSearchMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->results = [];
            $this->total = 0;
            $this->cacheHit = null;
            $this->errors = ['search' => $e->getMessage()];

            Notification::make()
                ->danger()
                ->title('Search failed')
                ->body($e->getMessage())
                ->send();

            return;
        }

        $this->lastSearchMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->results = array_map(
            fn (ExternalProductData $item): array => $this->presentItem($item),
            $result->items
        );
        $this->total = $result->total;
        $this->cacheHit = (bool) data_get($result->providerMeta, 'cache.hit', false);
        $this->errors = $this->collectErrors($result->providerMeta);

        if ($this->errors !== null) {
            Notification::make()
                ->warning()
                ->title('Some marketplaces did not answer')
                ->body(implode(' · ', array_map(
                    fn (string $code, string $message): string => $this->providerName($code) . ': ' . $message,
                    array_keys($this->errors),
                    $this->errors
                )))
                ->send();
        }
    }

    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);

        $this->search(resetPage: false);
    }

    public function resetSearch(): void
    {
        $this->mount();
    }

    /**
     * @return array<string, string> provider code => display name
     */
    public function providerOptions(): array
    {
        return app(ProviderRegistry::class)
            ->all()
            ->map(static fn ($provider): string => $provider->displayName())
            ->all();
    }

    /**
     * Total number of pages for the current result set.
     */
    public function getLastPage(): int
    {
        $perPage = max(1, (int) ($this->perPage ?? 20));

        return max(1, (int) ceil(($this->total ?? 0) / $perPage));
    }

    /**
     * Window of page numbers to render around the current page.
     *
     * @return int[]
     */
    public function getPaginationWindow(int $radius = 2): array
    {
        $lastPage = $this->getLastPage();
        $start = max(1, $this->page - $radius);
        $end = min($lastPage, $this->page + $radius);

        return range($start, $end);
    }

    /**
     * @return array<string, string>
     */
    private static function sortOptions(): array
    {
        return [
            SearchSortField::Relevance->value => 'Relevance',
            SearchSortField::PriceAsc->value => 'Price: low to high',
            SearchSortField::PriceDesc->value => 'Price: high to low',
            SearchSortField::RatingDesc->value => 'Rating',
            SearchSortField::Newest->value => 'Newest',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(ExternalProductData $item): array
    {
        return [
            'fingerprint' => $item->fingerprint(),
            'title' => $item->title,
            'brand' => $item->brand,
            'price' => $item->priceFormatted(),
            'oldPrice' => $item->oldPriceAmount !== null
                ? number_format($item->oldPriceAmount / 100, 2, '.', ' ') . ' ' . $item->currency
                : null,
            'discountPercent' => $this->discountPercent($item),
            'providerCode' => $item->providerCode,
            'providerName' => $this->providerName($item->providerCode),
            'imageUrl' => $item->primaryImageUrl(),
            'productUrl' => $item->productUrl,
            'rating' => $item->ratingValue,
            'ratingCount' => $item->ratingCount,
            'stockQuantity' => $item->stockQuantity,
            'availability' => $item->availabilityStatus,
            'category' => $item->categoryName,
        ];
    }

    private function discountPercent(ExternalProductData $item): ?int
    {
        if ($item->priceAmount === null || $item->oldPriceAmount === null || $item->oldPriceAmount <= $item->priceAmount) {
            return null;
        }

        return (int) round(100 - ($item->priceAmount / $item->oldPriceAmount * 100));
    }

    /**
     * Pull the per-provider failures out of the aggregated meta payload.
     *
     * @param array<string, mixed> $providerMeta
     * @return array<string, string>|null
     */
    private function collectErrors(array $providerMeta): ?array
    {
        $errors = [];

        foreach ($providerMeta as $code => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            if (($meta['status'] ?? null) === 'failed' || isset($meta['error'])) {
                $errors[(string) $code] = (string) ($meta['error'] ?? 'Unknown provider error.');
            }
        }

        return $errors === [] ? null : $errors;
    }

    private function providerName(string $code): string
    {
        $registry = app(ProviderRegistry::class);

        return $registry->has($code)
            ? $registry->get($code)->displayName()
            : $code;
    }

    private function toKopecks(int|string|null $roubles): ?int
    {
        if ($roubles === null || $roubles === '') {
            return null;
        }

        return (int) round(((float) $roubles) * 100);
    }
}
