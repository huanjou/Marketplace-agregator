<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Contracts\ProductProviderInterface;
use App\Services\ProviderRegistry;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Live health of every enabled marketplace provider.
 *
 * Health checks can hit the network, so the whole snapshot is memoised for a
 * minute — the widget is rendered on every page load of the search board.
 */
class ProviderStatusWidget extends StatsOverviewWidget
{
    private const CACHE_KEY = 'admin.provider-health-snapshot';

    private const CACHE_TTL_SECONDS = 60;

    protected ?string $heading = 'Marketplace health';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Stat[]
     */
    protected function getStats(): array
    {
        $snapshot = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->snapshot()
        );

        if ($snapshot === []) {
            return [
                Stat::make('Providers', 'none enabled')
                    ->description('Enable a marketplace in config/marketplace.php')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('warning'),
            ];
        }

        return array_map(
            static fn (array $row): Stat => Stat::make($row['name'], $row['status'])
                ->description($row['message'])
                ->descriptionIcon(match ($row['status']) {
                    'healthy' => 'heroicon-m-check-circle',
                    'degraded' => 'heroicon-m-exclamation-triangle',
                    default => 'heroicon-m-x-circle',
                })
                ->color(match ($row['status']) {
                    'healthy' => 'success',
                    'degraded' => 'warning',
                    default => 'danger',
                }),
            $snapshot
        );
    }

    /**
     * @return array<int, array{name: string, status: string, message: string}>
     */
    private function snapshot(): array
    {
        return app(ProviderRegistry::class)
            ->enabled()
            ->map(static function (ProductProviderInterface $provider): array {
                try {
                    $health = $provider->healthCheck();

                    return [
                        'name' => $provider->displayName(),
                        'status' => $health->status,
                        'message' => trim(sprintf(
                            '%s %s',
                            $health->responseTimeMs !== null ? $health->responseTimeMs . ' ms ·' : '',
                            $health->message ?? $provider->code()
                        )),
                    ];
                } catch (Throwable $e) {
                    return [
                        'name' => $provider->displayName(),
                        'status' => 'down',
                        'message' => $e->getMessage(),
                    ];
                }
            })
            ->values()
            ->all();
    }
}
