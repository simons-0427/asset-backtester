<?php

declare(strict_types=1);

use Smstark\Weltmarkt\Http\JsonResponse;

require_once dirname(__DIR__, 2) . '/includes/weltmarkt/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    JsonResponse::error('METHOD_NOT_ALLOWED', 'Nur GET-Anfragen sind erlaubt.', 405);
}

try {
    $app = require dirname(__DIR__, 2) . '/includes/weltmarkt/bootstrap.php';
    JsonResponse::send([
        'success' => true,
        'metrics' => $app->metrics()->all(),
        'manifest' => $app->metrics()->manifest(),
        'generatedAt' => gmdate(DATE_ATOM),
    ], 200, 300);
} catch (Throwable $exception) {
    error_log('[weltmarkt/metrics] ' . $exception->getMessage());
    JsonResponse::error('DATABASE_UNAVAILABLE', 'Die gespeicherten Weltmarkt-Daten konnten nicht geladen werden.', 503);
}
