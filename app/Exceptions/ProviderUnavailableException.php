<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ProviderUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        string $message = '',
        int $code = 503,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?: "Provider [{$providerCode}] is currently unavailable", $code, $previous);
    }
}
