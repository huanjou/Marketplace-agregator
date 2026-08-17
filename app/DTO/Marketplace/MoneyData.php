<?php

declare(strict_types=1);

namespace App\DTO\Marketplace;

readonly class MoneyData
{
    public function __construct(
        public int $amount,       // minor units (kopecks)
        public string $currency = 'RUB',
    ) {}

    public function toDecimal(): float
    {
        return $this->amount / 100;
    }

    public function formatted(): string
    {
        return number_format($this->toDecimal(), 2, '.', ' ') . ' ' . $this->currency;
    }
}
