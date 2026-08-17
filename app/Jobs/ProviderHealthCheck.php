<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\Provider;
use App\Models\SyncLog;
use App\Services\ProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Probes every registered provider and mirrors the verdict onto the providers
 * table so the admin panel can show liveness without calling out itself.
 *
 * Disabled providers are probed too — healthCheck() is expected to be cheap
 * and a disabled provider that reports itself unconfigured is useful signal.
 * A failing provider never aborts the sweep.
 */
class ProviderHealthCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(ProviderRegistry $registry): void
    {
        foreach ($registry->all() as $provider) {
            $log = SyncLog::create([
                'provider_code' => $provider->code(),
                'operation' => 'health_check',
                'status' => SyncStatus::Running->value,
                'started_at' => now(),
            ]);

            try {
                $health = $provider->healthCheck();

                Provider::query()->where('code', $provider->code())->update([
                    'last_health_status' => $health->status,
                    'last_checked_at' => now(),
                ]);

                $log->markCompleted(
                    $health->isHealthy() ? SyncStatus::Succeeded->value : SyncStatus::Partial->value,
                    [
                        'status' => $health->status,
                        'response_time_ms' => $health->responseTimeMs,
                        'message' => $health->message,
                    ],
                );
            } catch (\Throwable $e) {
                Provider::query()->where('code', $provider->code())->update([
                    'last_health_status' => 'down',
                    'last_checked_at' => now(),
                ]);

                $log->fill([
                    'status' => SyncStatus::Failed->value,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'finished_at' => now(),
                ])->save();
            }
        }
    }
}
