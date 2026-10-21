<?php

declare(strict_types=1);

use Smstark\Backtester\Http\JsonResponse;
use Smstark\Backtester\Http\RequestSecurity;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    RequestSecurity::requireCliOrCronSecret($app->config['security']);
    $result = $app->syncService()->syncDue('cron');

    if (RequestSecurity::isCommandLineExecution()) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit($result['assets_failed'] > 0 ? 1 : 0);
    }
    JsonResponse::send(['success' => $result['assets_failed'] === 0, 'result' => $result]);
} catch (Throwable $error) {
    error_log('Backtester daily cron: ' . $error->getMessage());
    if (RequestSecurity::isCommandLineExecution()) {
        echo "Daily update failed. Details are in the server log.\n";
        exit(1);
    }
    JsonResponse::error('CRON_FAILED', 'Der tägliche Datenabgleich ist fehlgeschlagen.', 500);
}
