<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/weltmarkt/autoload.php';

try {
    $app = require $root . '/includes/weltmarkt/bootstrap.php';
    $settings = $app->importSettings();
    $jobKey = null;
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with($argument, '--job=')) {
            $jobKey = substr($argument, 6);
        }
    }

    $result = is_string($jobKey) && $jobKey !== ''
        ? $app->imports()->runJob($jobKey, 'manual')
        : $app->imports()->runDue(
            'cron',
            (int) ($settings['max_jobs_per_run'] ?? 4),
            (int) ($settings['max_runtime_seconds'] ?? 700),
        );

    echo json_encode(
        ['success' => true, 'generated_at' => gmdate(DATE_ATOM), 'result' => $result],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;

    $statuses = isset($result['jobs'])
        ? array_column($result['jobs'], 'status')
        : [(string) ($result['status'] ?? 'success')];
    exit(array_intersect($statuses, ['partial', 'failed']) === [] ? 0 : 2);
} catch (Throwable $error) {
    error_log('[weltmarkt/cron] ' . $error->getMessage());
    fwrite(STDERR, json_encode([
        'success' => false,
        'error' => ['code' => 'IMPORT_START_FAILED', 'message' => 'Der Import konnte nicht gestartet werden.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
