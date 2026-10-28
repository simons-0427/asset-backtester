<?php

declare(strict_types=1);

use Smstark\Backtester\Http\JsonResponse;
use Smstark\Backtester\Http\RequestSecurity;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    RequestSecurity::requireCliOrCronSecret($app->config['security']);
    $result = $app->syncService()->syncDue('cron');
    $result['dividend_yield'] = $app->dividendYieldSyncService()->syncDue();
    $success = $result['assets_failed'] === 0
        && ($result['dividend_yield']['status'] ?? 'failed') !== 'failed';

    if (RequestSecurity::isCommandLineExecution()) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit($success ? 0 : 1);
    }
    JsonResponse::send(['success' => $success, 'result' => $result]);
} catch (Throwable $error) {
    error_log('Backtester daily cron: ' . $error->getMessage());
    if (RequestSecurity::isCommandLineExecution()) {
        echo "Daily update failed. Details are in the server log.\n";
        exit(1);
    }
    JsonResponse::error('CRON_FAILED', 'Der tägliche Datenabgleich ist fehlgeschlagen.', 500);
}
