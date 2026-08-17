<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Marketplace\ExternalProductData;
use App\DTO\Marketplace\ProviderCapabilityData;
use App\DTO\Marketplace\ProviderHealthData;
use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;

interface ProductProviderInterface
{
    public function code(): string;

    public function displayName(): string;

    public function capabilities(): ProviderCapabilityData;

    public function isEnabled(): bool;

    public function search(ProductSearchQuery $query): ProductSearchResult;

    public function fetchByExternalId(string $externalId): ?ExternalProductData;

    public function healthCheck(): ProviderHealthData;
}
