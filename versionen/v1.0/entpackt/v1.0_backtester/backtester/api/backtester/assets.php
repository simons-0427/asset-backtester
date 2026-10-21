<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\ValidationException;
use Smstark\Backtester\Http\JsonResponse;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    $assets = array_map(
        static fn ($asset): array => $asset->toPublicArray(),
        $app->assets()->active()
    );
    JsonResponse::send([
        'success' => true,
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        'assets' => $assets,
    ], 200, 300);
} catch (ValidationException $error) {
    JsonResponse::error($error->errorCode, $error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Backtester assets endpoint: ' . $error->getMessage());
    JsonResponse::error('SERVICE_UNAVAILABLE', 'Die Asset-Metadaten sind vorübergehend nicht verfügbar.', 503);
}

