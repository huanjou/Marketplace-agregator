<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchCacheItem extends Model
{
    use HasFactory;

    protected $table = 'search_cache_items';

    protected $fillable = [
        'search_cache_id',
        'provider_product_id',
        'provider_code',
        'external_product_id',
        'rank',
        'score',
        'snapshot',
    ];

    protected $casts = [
        'rank' => 'integer',
        'score' => 'float',
        'snapshot' => 'array',
    ];

    public function searchCache(): BelongsTo
    {
        return $this->belongsTo(SearchCache::class);
    }

    public function providerProduct(): BelongsTo
    {
        return $this->belongsTo(ProviderProduct::class);
    }
}
