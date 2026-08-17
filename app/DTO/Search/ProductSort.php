<?php

declare(strict_types=1);

namespace App\DTO\Search;

use App\Enums\SearchSortField;

readonly class ProductSort
{
    public function __construct(
        public SearchSortField $field = SearchSortField::Relevance,
        public string $direction = 'desc',
    ) {}
}
