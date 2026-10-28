<?php

declare(strict_types=1);

namespace Smstark\Backtester\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'VALIDATION_ERROR')
    {
        parent::__construct($message);
    }
}

