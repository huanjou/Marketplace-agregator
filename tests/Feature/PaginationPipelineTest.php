<?php

namespace Tests\Feature;

use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Services\ProductSearchService;
use Tests\TestCase;

/**
 * Guards the pagination contract: providers hand over their full match set and
 * ResultAggregator is the only component that slices a page out of it.
 */
class PaginationPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each page is cached under its own key; an in-memory store keeps the
        // test independent of whatever the shared cache already holds.
        config(['cache.default' => 'array']);
    }

    public function test_the_first_page_is_full_and_reports_the_whole_match_set(): void
    {
        $result = $this->search(page: 1);

        $this->assertCount(10, $result->items);
        $this->assertGreaterThanOrEqual(42, $result->total);
    }

    public function test_the_second_page_is_not_empty(): void
    {
        $first = $this->search(page: 1);
        $second = $this->search(page: 2);

        $this->assertNotEmpty($second->items);
        $this->assertSame($first->total, $second->total);

        $firstIds = array_map(static fn ($item): ?string => $item->externalProductId, $first->items);
        $secondIds = array_map(static fn ($item): ?string => $item->externalProductId, $second->items);

        $this->assertSame([], array_intersect($firstIds, $secondIds));
    }

    public function test_the_last_page_is_partial_and_pages_past_the_end_are_empty(): void
    {
        $first = $this->search(page: 1);

        $last = $this->search(page: 5);
        $this->assertCount(max(0, $first->total - 40), $last->items);
        $this->assertSame($first->total, $last->total);

        $beyond = $this->search(page: 9);
        $this->assertCount(0, $beyond->items);
        $this->assertSame($first->total, $beyond->total);
        $this->assertNull($beyond->nextCursor);
    }

    private function search(int $page): ProductSearchResult
    {
        return app(ProductSearchService::class)->search(
            new ProductSearchQuery(text: '', page: $page, perPage: 10)
        );
    }
}
