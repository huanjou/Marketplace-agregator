<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Enums\ProviderCode;
use App\Services\Ai\PerplexitySearchUrlService;
use App\Services\ProductSearchService;
use App\Services\ProviderRegistry;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
    /** New searches per IP per minute — the fan-out hits live scrapers. */
    private const RATE_LIMIT_PER_MINUTE = 15;

    /**
     * Page flips per IP per minute. These are served from the cached match
     * set (no scraper traffic), so the budget is generous — it only guards
     * against the rare re-run after the cache TTL expires.
     */
    private const PAGE_RATE_LIMIT_PER_MINUTE = 60;

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

    /**
     * Human-readable pipeline phase, rendered between the chained round trips
     * (search -> resolve-search-urls -> run-scrape) so the user sees what the
     * long request is doing. Null once results are ready.
     */
    public ?string $status = null;

    /** @var array<string, string> provider code => AI-built marketplace search URL */
    public array $searchUrls = [];

    /** Whether the finished search actually used AI-built SERP URLs. */
    public bool $aiUrlsApplied = false;

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
            ->enabled()
            ->except(ProviderCode::Fake->value)
            ->map(static fn ($provider): string => $provider->displayName())
            ->all();
    }

    /**
     * Validation + rate limit only: the heavy work is split into chained
     * round trips (resolve-search-urls -> run-scrape) so the UI can render
     * the «Ищем ссылки...» phase before the scrapers start.
     */
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

        $rateKey = ($resetPage ? 'public-search:' : 'public-search:page:') . request()->ip();
        $rateLimit = $resetPage ? self::RATE_LIMIT_PER_MINUTE : self::PAGE_RATE_LIMIT_PER_MINUTE;

        if (! RateLimiter::attempt($rateKey, $rateLimit, static fn (): bool => true)) {
            $this->notice = 'Слишком много запросов. Подождите минуту и попробуйте снова.';

            return;
        }

        if ($resetPage) {
            $this->page = 1;
        }

        $this->query = $text;
        $this->searched = false;
        $this->results = null;
        $this->providerErrors = null;
        $this->searchUrls = [];
        $this->aiUrlsApplied = false;
        $this->status = 'Ищем ссылки...';

        $this->dispatch('resolve-search-urls');
    }

    /**
     * Phase 2: ask Perplexity for ready-to-open marketplace search URLs with
     * filters baked in. Strictly best-effort — on any failure the providers
     * keep their plain-text search path.
     */
    #[On('resolve-search-urls')]
    public function resolveSearchUrls(): void
    {
        try {
            $urls = app(PerplexitySearchUrlService::class)->urlsFor(trim($this->query));
        } catch (Throwable) {
            $urls = [];
        }

        // Never fan out to excluded providers even if the snapshot is tampered with.
        $allowed = array_flip(array_keys($this->providerOptions()));
        $selected = array_intersect_key($allowed, array_flip($this->providerCodes));

        $this->searchUrls = array_intersect_key($urls, $selected);
        $this->status = 'Собираем товары с маркетплейсов...';

        $this->dispatch('run-scrape');
    }

    /**
     * Phase 3: run the usual search pipeline, handing each provider its
     * AI-built SERP URL when one was resolved.
     */
    #[On('run-scrape')]
    public function runScrape(): void
    {
        $text = trim($this->query);

        $allowed = array_keys($this->providerOptions());
        $codes = array_values(array_intersect($this->providerCodes, $allowed));

        if (mb_strlen($text) < 2 || $codes === []) {
            $this->status = null;

            return;
        }

        $query = new ProductSearchQuery(
            text: $text,
            filters: new ProductSearchFilters(),
            sort: new ProductSort(),
            page: max(1, $this->page),
            perPage: $this->perPage,
            providerCodes: $codes,
            searchUrls: $this->searchUrls,
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
            $this->status = null;

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
        $this->aiUrlsApplied = $this->searchUrls !== [];
        $this->status = null;
    }

    public function gotoPage(int $page): void
    {
        if (! RateLimiter::attempt(
            'public-search:page:' . request()->ip(),
            self::PAGE_RATE_LIMIT_PER_MINUTE,
            static fn (): bool => true,
        )) {
            $this->notice = 'Слишком много запросов. Подождите минуту и попробуйте снова.';

            return;
        }

        $this->page = max(1, $page);
        $this->status = 'Собираем товары с маркетплейсов...';

        // The URLs resolved for the current query stay valid across pages.
        $this->dispatch('run-scrape');
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
            'providerCode' => $item->providerCode,
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
