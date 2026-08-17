<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use HasFactory;

    protected $table = 'providers';

    protected $fillable = [
        'code',
        'name',
        'provider_class',
        'enabled',
        'supports_realtime_search',
        'supports_catalog_sync',
        'capabilities',
        'rate_limit_per_minute',
        'cache_ttl_seconds',
        'last_health_status',
        'last_checked_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'supports_realtime_search' => 'boolean',
        'supports_catalog_sync' => 'boolean',
        'capabilities' => 'array',
        'rate_limit_per_minute' => 'integer',
        'cache_ttl_seconds' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    public function providerProducts(): HasMany
    {
        return $this->hasMany(ProviderProduct::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
