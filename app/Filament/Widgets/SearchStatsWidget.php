<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Product;
use App\Models\ProviderProduct;
use App\Models\SearchCache;
use App\Models\SyncLog;
use App\Services\ProviderRegistry;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Volume counters for the aggregation pipeline.
 */
class SearchStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Pipeline';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 5;
    }

    /**
     * @return Stat[]
     */
    protected function getStats(): array
    {
        $registry = app(ProviderRegistry::class);
        $failedSyncs = SyncLog::query()
            ->failed()
            ->where('started_at', '>=', Carbon::now()->subDay())
            ->count();

        return [
            Stat::make('Providers', (string) count($registry->codes()))
                ->description($registry->enabled()->count() . ' enabled')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('primary'),

            Stat::make('Cached searches', $this->formatted(SearchCache::query()->count()))
                ->description('Mirrored query snapshots')
                ->descriptionIcon('heroicon-m-bolt'),

            Stat::make('Normalized products', $this->formatted(Product::query()->count()))
                ->description('Canonical catalogue rows')
                ->descriptionIcon('heroicon-m-cube'),

            Stat::make('Provider offers', $this->formatted(ProviderProduct::query()->count()))
                ->description('Per-marketplace offers')
                ->descriptionIcon('heroicon-m-tag'),

            Stat::make('Failed syncs (24h)', $this->formatted($failedSyncs))
                ->description($failedSyncs === 0 ? 'All clear' : 'Check the sync logs')
                ->descriptionIcon($failedSyncs === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($failedSyncs === 0 ? 'success' : 'danger'),
        ];
    }

    private function formatted(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }
}
