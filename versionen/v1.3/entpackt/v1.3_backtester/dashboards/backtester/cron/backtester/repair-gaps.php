<?php

declare(strict_types=1);

use Smstark\Backtester\Http\JsonResponse;
use Smstark\Backtester\Http\RequestSecurity;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

function repairOptions(): array
{
    if (RequestSecurity::isCommandLineExecution()) {
        return RequestSecurity::commandLineOptions(['asset', 'from', 'to']);
    }
    return array_intersect_key($_GET, array_flip(['asset', 'from', 'to']));
}

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    RequestSecurity::requireCliOrCronSecret($app->config['security']);
    $options = repairOptions();
    $from = new DateTimeImmutable((string) ($options['from'] ?? '60 days ago'));
    $to = new DateTimeImmutable((string) ($options['to'] ?? 'yesterday'));
    $assets = isset($options['asset'])
        ? array_filter([$app->assets()->bySlug((string) $options['asset'])])
        : $app->assets()->active();
    if ($assets === []) {
        throw new RuntimeException('Keine passende aktive Anlageklasse gefunden.');
    }
    $results = [];

    foreach ($assets as $asset) {
        $rows = $app->observations()->range($asset->id, $from, $to);
        $known = array_fill_keys(array_column($rows, 'observation_date'), true);
        $gaps = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $isExpected = $asset->calendarType === 'calendar_daily' || (int) $day->format('N') <= 5;
            if ($isExpected && !isset($known[$day->format('Y-m-d')])) {
                $gaps[] = $day->format('Y-m-d');
            }
        }

        if ($asset->calendarType === 'market_weekdays' && count($gaps) < 3) {
            $results[$asset->slug] = ['candidateGaps' => $gaps, 'action' => 'ignored_possible_holiday'];
            continue;
        }
        if ($gaps === []) {
            $results[$asset->slug] = ['candidateGaps' => [], 'action' => 'none'];
            continue;
        }

        $repairFrom = (new DateTimeImmutable($gaps[0]))->modify('-3 days');
        $repairTo = (new DateTimeImmutable(end($gaps)))->modify('+3 days');
        $boundedRepairTo = $repairTo < $to ? $repairTo : $to;
        $result = $app->syncService()->syncAssets([$asset], 'gap_repair', $repairFrom, $boundedRepairTo);
        $results[$asset->slug] = ['candidateGaps' => $gaps, 'action' => 'refetched', 'result' => $result];
    }

    if (RequestSecurity::isCommandLineExecution()) {
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    JsonResponse::send(['success' => true, 'results' => $results]);
} catch (Throwable $error) {
    error_log('Backtester gap repair: ' . $error->getMessage());
    if (RequestSecurity::isCommandLineExecution()) {
        echo "Gap repair failed. Details are in the server log.\n";
        exit(1);
    }
    JsonResponse::error('GAP_REPAIR_FAILED', 'Die Lückenprüfung ist fehlgeschlagen.', 500);
}
