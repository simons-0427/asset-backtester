<?php

declare(strict_types=1);

namespace Smstark\Backtester\Exceptions;

use RuntimeException;

final class ProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly string $providerCode = 'PROVIDER_ERROR')
    {
        parent::__construct($message);
    }
}

