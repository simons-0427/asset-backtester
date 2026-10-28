<?php

declare(strict_types=1);

namespace Smstark\Backtester\Tests;

use RuntimeException;

final class Assertions
{
    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message ?: sprintf(
                'Expected %s, got %s',
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public static function near(float $expected, float $actual, float $tolerance = 0.0001, string $message = ''): void
    {
        if (abs($expected - $actual) > $tolerance) {
            throw new RuntimeException($message ?: sprintf('Expected %.8f, got %.8f', $expected, $actual));
        }
    }

    public static function true(bool $condition, string $message = 'Expected condition to be true'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function throws(callable $callback, string $exceptionClass): void
    {
        try {
            $callback();
        } catch (\Throwable $error) {
            if ($error instanceof $exceptionClass) {
                return;
            }
            throw new RuntimeException('Unexpected exception: ' . $error::class . ' ' . $error->getMessage());
        }
        throw new RuntimeException('Expected exception ' . $exceptionClass . ' was not thrown.');
    }
}

