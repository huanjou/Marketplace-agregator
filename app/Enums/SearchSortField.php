<?php

declare(strict_types=1);

namespace App\Enums;

enum SearchSortField: string
{
    case Relevance = 'relevance';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case RatingDesc = 'rating_desc';
    case Newest = 'newest';
}
