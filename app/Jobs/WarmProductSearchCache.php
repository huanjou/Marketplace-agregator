<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTO\Search\ProductSearchFilters;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Enums\SearchSortField;
use App\Services\ProductSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pre-executes a search so the next real visitor hits a warm cache.
 *
 * ProductSearchQuery is a readonly DTO holding enums and nested value objects,
 * so the job carries a plain-array snapshot instead: it survives Laravel's
 * payload serialization and is rebuilt through the constructor in handle().
 */
class WarmProductSearchCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * @param array<string, mixed> $querySnapshot
     */
    public function __construct(public readonly array $querySnapshot) {}

    public static function forQuery(ProductSearchQuery $query): self
    {
        return new self([
            'text' => $query->text,
            'filters' => $query->filters->toArray(),
            'sort_field' => $query->sort->field->value,
            'sort_direction' => $query->sort->direction,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'provider_codes' => $query->providerCodes,
            'timeout_ms' => $query->timeoutMs,
        ]);
    }

    public function handle(ProductSearchService $service): void
    {
        $query = $this->restoreQuery();

        $result = $service->search($query);

        Log::info('search_cache_warmed', [
            'text' => $query->text,
            'providers' => $query->providerCodes,
            'total' => $result->total,
            'items' => count($result->items),
        ]);
    }

    private function restoreQuery(): ProductSearchQuery
    {
        $snapshot = $this->querySnapshot;
        $filters = $snapshot['filters'] ?? [];

        return new ProductSearchQuery(
            text: $snapshot['text'] ?? '',
            filters: new ProductSearchFilters(
                minPriceAmount: $filters['min_price_amount'] ?? null,
                maxPriceAmount: $filters['max_price_amount'] ?? null,
                currency: $filters['currency'] ?? null,
                categoryId: $filters['category_id'] ?? null,
                externalCategoryIds: $filters['external_category_ids'] ?? [],
                brands: $filters['brands'] ?? [],
                minRating: $filters['min_rating'] ?? null,
                availability: $filters['availability'] ?? null,
                attributes: $filters['attributes'] ?? [],
            ),
            sort: new ProductSort(
                field: SearchSortField::from($snapshot['sort_field'] ?? SearchSortField::Relevance->value),
                direction: $snapshot['sort_direction'] ?? 'desc',
            ),
            page: $snapshot['page'] ?? 1,
            perPage: $snapshot['per_page'] ?? 20,
            providerCodes: $snapshot['provider_codes'] ?? [],
            timeoutMs: $snapshot['timeout_ms'] ?? 5000,
        );
    }
}
