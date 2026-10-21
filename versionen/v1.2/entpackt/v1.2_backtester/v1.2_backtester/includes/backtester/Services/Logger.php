<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

final class Logger
{
    public function __construct(private readonly string $file)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $sanitized = $this->sanitize($context);
        $line = sprintf(
            "[%s] %s %s%s\n",
            gmdate('c'),
            $level,
            $message,
            $sanitized === [] ? '' : ' ' . json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $directory = dirname($this->file);
        if (is_dir($directory) && is_writable($directory)) {
            error_log($line, 3, $this->file);
            return;
        }
        error_log(trim($line));
    }

    private function sanitize(array $context): array
    {
        $blocked = ['api_key', 'apikey', 'password', 'token', 'secret', 'authorization'];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }
        return $context;
    }
}

