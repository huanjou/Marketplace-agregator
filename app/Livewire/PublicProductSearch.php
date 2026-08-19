<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Enums\ProviderCode;
use App\Services\ProductSearchService;
use App\Services\ProviderRegistry;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Public (no auth required) product search page.
 *
 * Reuses the exact same ProductSearchService pipeline as the admin dashboard;
 * the only differences are presentation and a per-IP rate limit, because the
 * fan-out drives real marketplace scrapers and must not be abusable.
 */
#[Layout('components.layouts.public')]
class PublicProductSearch extends Component
{
    /** Searches per IP per minute — the fan-out hits live scrapers. */
    private const RATE_LIMIT_PER_MINUTE = 15;

    public string $query = '';

    /** @var string[] */
    public array $providerCodes = [];

    /** @var array<int, array<string, mixed>>|null */
    public ?array $results = null;

    public int $total = 0;

    public int $page = 1;

    public int $perPage = 20;

    public ?int $lastSearchMs = null;

    /** @var array<string, string>|null provider code => error message */
    public ?array $providerErrors = null;

    public ?string $notice = null;

    public bool $searched = false;

    public function mount(): void
    {
        $this->providerCodes = array_keys($this->providerOptions());
    }

    /**
     * @return array<string, string> provider code => display name
     */
    public function providerOptions(): array
    {
        // The fake provider exists for tests/demo only; it must never leak
        // synthetic items into the public search page.
        return app(ProviderRegistry::class)
            ->all()
            ->except(ProviderCode::Fake->value)
            ->map(static fn ($provider): string => $provider->displayName())
            ->all();
    }

    public function search(bool $resetPage = true): void
    {
        $this->notice = null;

        $text = trim($this->query);

        if (mb_strlen($text) < 2) {
            $this->notice = 'Введите минимум 2 символа для поиска.';

            return;
        }

        if ($this->providerCodes === []) {
            $this->notice = 'Выберите хотя бы один маркетплейс.';

            return;
        }

        $rateKey = 'public-search:' . request()->ip();

        if (! RateLimiter::attempt($rateKey, self::RATE_LIMIT_PER_MINUTE, static fn (): bool => true)) {
            $this->notice = 'Слишком много запросов. Подождите минуту и попробуйте снова.';

            return;
        }

        if ($resetPage) {
            $this->page = 1;
        }

        $this->query = $text;

        // Never fan out to excluded providers even if the snapshot is tampered with.
        $allowed = array_keys($this->providerOptions());
        $codes = array_values(array_intersect($this->providerCodes, $allowed));

        if ($codes === []) {
            $this->notice = 'Выберите хотя бы один маркетплейс.';

            return;
        }

        $query = new ProductSearchQuery(
            text: $text,
            filters: new ProductSearchFilters(),
            sort: new ProductSort(),
            page: max(1, $this->page),
            perPage: $this->perPage,
            providerCodes: $codes,
        );

        $startedAt = microtime(true);

        try {
            $result = app(ProductSearchService::class)->search($query);
        } catch (Throwable $e) {
            $this->searched = true;
            $this->results = [];
            $this->total = 0;
            $this->providerErrors = null;
            $this->lastSearchMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->notice = 'Поиск временно недоступен: ' . $e->getMessage();

            return;
        }

        $this->searched = true;
        $this->lastSearchMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->results = array_map(
            fn (ExternalProductData $item): array => $this->presentItem($item),
            $result->items
        );
        $this->total = $result->total;
        $this->providerErrors = $this->collectErrors($result->providerMeta);
    }

    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);

        $this->search(resetPage: false);
    }

    public function getLastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    /**
     * @return int[]
     */
    public function getPaginationWindow(int $radius = 2): array
    {
        $lastPage = $this->getLastPage();

        return range(max(1, $this->page - $radius), min($lastPage, $this->page + $radius));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(ExternalProductData $item): array
    {
        return [
            'fingerprint' => $item->fingerprint(),
            'title' => $item->title,
            'price' => $item->priceFormatted(),
            'oldPrice' => $item->oldPriceAmount !== null && $item->oldPriceAmount > ($item->priceAmount ?? 0)
                ? number_format($item->oldPriceAmount / 100, 0, '.', ' ') . ' ₽'
                : null,
            'providerName' => $this->providerName($item->providerCode),
            'imageUrl' => $item->primaryImageUrl(),
            'productUrl' => $item->productUrl,
            'rating' => $item->ratingValue,
            'ratingCount' => $item->ratingCount,
            'availability' => $item->availabilityStatus,
        ];
    }

    /**
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
                $errors[(string) $code] = $this->providerName((string) $code) . ': ' . (string) ($meta['error'] ?? 'Unknown error');
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

    public function render()
    {
        return view('livewire.public-product-search');
    }
}
