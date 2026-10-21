<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\ValidationException;
use Smstark\Backtester\Http\JsonResponse;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    $activeAssets = $app->assets()->active();
    $allowedSlugs = array_map(static fn ($asset): string => $asset->slug, $activeAssets);
    $request = $app->validator()->series($_GET, $allowedSlugs);
    $assets = $app->assets()->bySlugs($request['assets']);
    $result = $app->backtests()->run($assets, $request['start'], $request['end'], $request['amount']);

    JsonResponse::send([
        'success' => true,
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        'request' => [
            'assets' => $request['assets'],
            'amount' => $request['amount'],
            'start' => $request['start']->format('Y-m-d'),
            'end' => $request['end']->format('Y-m-d'),
            'currency' => 'EUR',
        ],
        'commonCalendar' => $result['commonCalendar'],
        'series' => $result['series'],
        'warnings' => $result['warnings'],
        'dataStatus' => $app->syncRepository()->publicStatus(),
    ], 200, 300);
} catch (ValidationException $error) {
    JsonResponse::error($error->errorCode, $error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Backtester series endpoint: ' . $error->getMessage());
    JsonResponse::error('SERVICE_UNAVAILABLE', 'Die gespeicherten Backtestdaten sind vorübergehend nicht verfügbar.', 503);
}

