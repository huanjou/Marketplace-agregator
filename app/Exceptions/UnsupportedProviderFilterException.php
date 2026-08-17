<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class UnsupportedProviderFilterException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        public readonly string $filterName,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?: "Provider [{$providerCode}] does not support filter [{$filterName}]", 400, $previous);
    }
}
