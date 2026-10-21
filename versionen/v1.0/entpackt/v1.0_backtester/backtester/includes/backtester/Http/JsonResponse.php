<?php

declare(strict_types=1);

namespace Smstark\Backtester\Http;

final class JsonResponse
{
    public static function send(array $payload, int $status = 200, int $maxAge = 0): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header($maxAge > 0
            ? sprintf('Cache-Control: public, max-age=%d, stale-while-revalidate=%d', $maxAge, $maxAge)
            : 'Cache-Control: no-store');

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        exit;
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): never
    {
        self::send([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ], static fn ($value): bool => $value !== null),
        ], $status);
    }
}
