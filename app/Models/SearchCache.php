<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchCache extends Model
{
    use HasFactory;

    protected $table = 'search_caches';

    protected $fillable = [
        'cache_key',
        'query_text',
        'filters',
        'sort',
        'providers',
        'result_count',
        'expires_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'providers' => 'array',
        'result_count' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SearchCacheItem::class);
    }

    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('expires_at', '>', CarbonImmutable::now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
