<?php

declare(strict_types=1);

use Smstark\Backtester\Http\JsonResponse;
use Smstark\Backtester\Http\RequestSecurity;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    RequestSecurity::requirePost();
    RequestSecurity::requireSameOrigin($app->config['security']);

    $syncRepository = $app->syncRepository();
    $limit = (int) $app->config['sync']['page_visit_rate_limit'];
    $window = (int) $app->config['sync']['page_visit_rate_window_minutes'];
    if (!$syncRepository->pageVisitAllowed($limit, $window)) {
        JsonResponse::send([
            'success' => true,
            'syncTriggered' => false,
            'reason' => 'rate_limited',
        ], 202);
    }

    $result = $app->syncService()->syncDue(
        'page_visit',
        (int) $app->config['sync']['page_visit_max_assets']
    );
    JsonResponse::send([
        'success' => true,
        'syncTriggered' => $result['assets_checked'] > 0,
        'result' => [
            'assetsChecked' => $result['assets_checked'],
            'assetsSucceeded' => $result['assets_succeeded'],
            'assetsFailed' => $result['assets_failed'],
            'assetsLocked' => $result['assets_locked'],
        ],
    ], 202);
} catch (Throwable $error) {
    error_log('Backtester stale sync endpoint: ' . $error->getMessage());
    JsonResponse::error('SYNC_UNAVAILABLE', 'Die Hintergrundaktualisierung konnte nicht gestartet werden.', 503);
}

