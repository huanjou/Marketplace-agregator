<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderProduct extends Model
{
    use HasFactory;

    protected $table = 'provider_products';

    protected $fillable = [
        'product_id',
        'provider_id',
        'provider_code',
        'external_product_id',
        'external_offer_id',
        'external_category_id',
        'external_url',
        'title',
        'brand',
        'price_amount',
        'old_price_amount',
        'currency',
        'availability_status',
        'stock_quantity',
        'rating_value',
        'rating_count',
        'sales_rank',
        'image_urls',
        'raw_payload',
        'last_synced_at',
    ];

    protected $casts = [
        'price_amount' => 'integer',
        'old_price_amount' => 'integer',
        'stock_quantity' => 'integer',
        'rating_value' => 'float',
        'rating_count' => 'integer',
        'sales_rank' => 'integer',
        'image_urls' => 'array',
        'raw_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function getPriceDecimalAttribute(): ?float
    {
        return $this->price_amount !== null ? $this->price_amount / 100 : null;
    }

    public function getOldPriceDecimalAttribute(): ?float
    {
        return $this->old_price_amount !== null ? $this->old_price_amount / 100 : null;
    }

    public function getPriceFormattedAttribute(): string
    {
        if ($this->price_amount === null) {
            return '—';
        }

        $formatted = number_format($this->price_amount / 100, 2, '.', ' ');

        return trim($formatted.' '.(string) $this->currency);
    }

    public function getPrimaryImageUrlAttribute(): ?string
    {
        $images = $this->image_urls;

        if (is_array($images) && count($images) > 0) {
            return $images[array_key_first($images)];
        }

        return null;
    }

    public function getHasDiscountAttribute(): bool
    {
        return $this->old_price_amount !== null
            && $this->price_amount !== null
            && $this->old_price_amount > $this->price_amount;
    }
}
