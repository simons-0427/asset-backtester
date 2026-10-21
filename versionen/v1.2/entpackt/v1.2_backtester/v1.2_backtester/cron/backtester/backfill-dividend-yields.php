<?php

declare(strict_types=1);

use Smstark\Backtester\Http\JsonResponse;
use Smstark\Backtester\Http\RequestSecurity;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    RequestSecurity::requireCliOrCronSecret($app->config['security']);
    $options = RequestSecurity::isCommandLineExecution()
        ? RequestSecurity::commandLineOptions(['from'])
        : array_intersect_key($_GET, ['from' => true]);
    $configuredFrom = (string) (
        $app->config['providers']['multpl_dividend_yield']['backfill_from']
        ?? '2016-07-01'
    );
    $from = new DateTimeImmutable((string) ($options['from'] ?? $configuredFrom));
    $result = $app->dividendYieldSyncService()->backfill($from);
    $success = ($result['status'] ?? '') === 'success';

    if (RequestSecurity::isCommandLineExecution()) {
        echo json_encode(
            ['success' => $success, 'result' => $result],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;
        exit($success ? 0 : 1);
    }
    JsonResponse::send(['success' => $success, 'result' => $result], $success ? 200 : 503);
} catch (Throwable $error) {
    error_log('Backtester dividend yield backfill: ' . $error->getMessage());
    if (RequestSecurity::isCommandLineExecution()) {
        echo "Dividend yield backfill failed. Details are in the server log.\n";
        exit(1);
    }
    JsonResponse::error(
        'DIVIDEND_BACKFILL_FAILED',
        'Der historische Dividendenabruf ist fehlgeschlagen.',
        500,
    );
}
