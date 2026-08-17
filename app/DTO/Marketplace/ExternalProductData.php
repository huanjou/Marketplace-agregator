<?php

declare(strict_types=1);

namespace App\DTO\Marketplace;

readonly class ExternalProductData
{
    /**
     * @param string[] $imageUrls
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public string $providerCode,
        public ?string $externalProductId = null,
        public ?string $externalOfferId = null,
        public string $title = '',
        public ?string $brand = null,
        public ?string $description = null,
        public ?int $priceAmount = null,       // minor units
        public ?int $oldPriceAmount = null,    // minor units
        public string $currency = 'RUB',
        public ?string $categoryExternalId = null,
        public ?string $categoryName = null,
        public array $imageUrls = [],
        public ?string $productUrl = null,
        public ?string $availabilityStatus = null,
        public ?int $stockQuantity = null,
        public ?float $ratingValue = null,
        public ?int $ratingCount = null,
        public array $rawPayload = [],
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', $this->providerCode . ':' . ($this->externalProductId ?? '') . ':' . ($this->externalOfferId ?? ''));
    }

    public function primaryImageUrl(): ?string
    {
        return $this->imageUrls[0] ?? null;
    }

    public function priceFormatted(): string
    {
        if ($this->priceAmount === null) {
            return '—';
        }

        return number_format($this->priceAmount / 100, 2, '.', ' ') . ' ' . $this->currency;
    }
}
