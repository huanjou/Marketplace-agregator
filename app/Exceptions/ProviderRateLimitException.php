<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ProviderRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        public readonly ?int $retryAfterSeconds = null,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?: "Rate limit exceeded for provider [{$providerCode}]", 429, $previous);
    }
}
