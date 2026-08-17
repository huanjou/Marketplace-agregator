<?php

declare(strict_types=1);

namespace App\DTO\Marketplace;

readonly class ProviderHealthData
{
    public function __construct(
        public string $providerCode,
        public string $status,         // 'healthy', 'degraded', 'down'
        public ?int $responseTimeMs = null,
        public ?string $message = null,
        public ?\DateTimeInterface $checkedAt = null,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }
}
