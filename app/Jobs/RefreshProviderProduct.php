<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Services\ProductResultNormalizer;
use App\Services\ProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-fetches a single offer from its provider and upserts the fresh payload.
 * Used for on-demand freshening of stale provider_products rows.
 */
class RefreshProviderProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $providerCode,
        public readonly string $externalId,
    ) {}

    public function handle(
        ProviderRegistry $registry,
        ProductResultNormalizer $normalizer,
    ): void {
        $log = SyncLog::create([
            'provider_code' => $this->providerCode,
            'operation' => 'product_refresh',
            'status' => SyncStatus::Running->value,
            'started_at' => now(),
            'request_summary' => ['external_id' => $this->externalId],
        ]);

        try {
            $provider = $registry->get($this->providerCode);

            if (! $provider->isEnabled()) {
                $log->markCompleted(SyncStatus::Failed->value, ['reason' => 'disabled']);

                return;
            }

            $product = $provider->fetchByExternalId($this->externalId);

            if ($product === null) {
                $log->markCompleted(SyncStatus::Failed->value, ['reason' => 'not_found']);

                return;
            }

            $normalizer->persist([$product]);

            $log->markCompleted(SyncStatus::Succeeded->value, ['refreshed' => 1]);
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
