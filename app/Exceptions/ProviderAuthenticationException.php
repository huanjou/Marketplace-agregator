<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ProviderAuthenticationException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        string $message = '',
        int $code = 401,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?: "Authentication failed for provider [{$providerCode}]", $code, $previous);
    }
}
