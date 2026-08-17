<?php

use App\Enums\ProviderCode;
use App\Jobs\ProviderHealthCheck;
use App\Jobs\SyncProviderCatalog;
use App\Models\SearchCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProviderHealthCheck())
    ->everyFiveMinutes()
    ->name('providers.health_check')
    ->onOneServer();

// Catalog sync is scheduled for every known provider; the job short-circuits
// at runtime for the ones that are disabled, so no credentials are needed.
foreach (ProviderCode::cases() as $providerCode) {
    Schedule::job(new SyncProviderCatalog(
        providerCode: $providerCode->value,
        seedQueries: [''],
        pagesPerQuery: 2,
        perPage: 100,
    ))
        ->hourly()
        ->name("providers.catalog_sync.{$providerCode->value}")
        ->onOneServer()
        ->withoutOverlapping();
}

Schedule::call(fn () => SearchCache::query()->where('expires_at', '<', now())->delete())
    ->hourly()
    ->name('search_caches.prune')
    ->onOneServer();
