<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\ValidationException;
use Smstark\Backtester\Http\JsonResponse;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    $end = $app->validator()->optionalEnd($_GET['end'] ?? null);
    $amount = filter_var($_GET['amount'] ?? 10000, FILTER_VALIDATE_FLOAT);
    $minimum = (float) $app->config['app']['min_amount'];
    $maximum = (float) $app->config['app']['max_amount'];
    if ($amount === false || $amount < $minimum || $amount > $maximum) {
        throw new ValidationException('Ungültiges Vergleichskapital.', 'INVALID_AMOUNT');
    }

    $summary = $app->summaries()->build($app->assets()->active(), $end, (float) $amount);
    JsonResponse::send([
        'success' => true,
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        'summary' => $summary,
        'dataStatus' => $app->syncRepository()->publicStatus(),
    ], 200, 300);
} catch (ValidationException $error) {
    JsonResponse::error($error->errorCode, $error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Backtester summary endpoint: ' . $error->getMessage());
    JsonResponse::error('SERVICE_UNAVAILABLE', 'Die Mehrperiodenübersicht ist vorübergehend nicht verfügbar.', 503);
}

