<?php

declare(strict_types=1);

use Smstark\Backtester\Http\JsonResponse;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    JsonResponse::send([
        'success' => true,
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        'status' => $app->syncRepository()->publicStatus(),
    ], 200, 60);
} catch (Throwable $error) {
    error_log('Backtester status endpoint: ' . $error->getMessage());
    JsonResponse::error('SERVICE_UNAVAILABLE', 'Der Datenstatus ist vorübergehend nicht verfügbar.', 503);
}

