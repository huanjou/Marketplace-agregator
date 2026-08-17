<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTO\Search\ProductSearchQuery;
use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Services\ProductResultNormalizer;
use App\Services\ProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Walks a provider's catalogue with a set of seed queries and persists every
 * returned offer into provider_products. Safe to re-run: persistence goes
 * through ProductResultNormalizer, which upserts on provider + external ids.
 */
class SyncProviderCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param string[] $seedQueries An empty string means "catch-all query".
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly array $seedQueries = [''],
        public readonly int $pagesPerQuery = 3,
        public readonly int $perPage = 100,
    ) {}

    public function handle(
        ProviderRegistry $registry,
        ProductResultNormalizer $normalizer,
    ): void {
        $log = SyncLog::create([
            'provider_code' => $this->providerCode,
            'operation' => 'catalog_sync',
            'status' => SyncStatus::Running->value,
            'started_at' => now(),
            'request_summary' => [
                'seed_queries' => $this->seedQueries,
                'pages_per_query' => $this->pagesPerQuery,
                'per_page' => $this->perPage,
            ],
        ]);

        try {
            $provider = $registry->get($this->providerCode);

            if (! $provider->isEnabled()) {
                $log->markCompleted(SyncStatus::Failed->value, ['reason' => 'disabled']);

                return;
            }

            $totalPersisted = 0;
            $failedPages = 0;

            foreach ($this->seedQueries as $text) {
                for ($page = 1; $page <= $this->pagesPerQuery; $page++) {
                    $query = new ProductSearchQuery(
                        text: $text,
                        page: $page,
                        perPage: $this->perPage,
                        providerCodes: [$this->providerCode],
                    );

                    try {
                        $result = $provider->search($query);
                    } catch (\Throwable $e) {
                        $failedPages++;

                        Log::warning('sync_page_failed', [
                            'provider' => $this->providerCode,
                            'text' => $text,
                            'page' => $page,
                            'error_class' => $e::class,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $count = count($result->items);

                    if ($count === 0) {
                        break;
                    }

                    $normalizer->persist($result->items);
                    $totalPersisted += $count;

                    // A short page is the last page — providers that ignore
                    // pagination (and return the full match set) would
                    // otherwise be re-walked pagesPerQuery times.
                    if ($count < $this->perPage) {
                        break;
                    }
                }
            }

            $log->markCompleted(
                $failedPages > 0 ? SyncStatus::Partial->value : SyncStatus::Succeeded->value,
                [
                    'persisted' => $totalPersisted,
                    'failed_pages' => $failedPages,
                ],
            );
        } catch (\Throwable $e) {
            $log->fill([
                'status' => SyncStatus::Failed->value,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }
}
