<?php

declare(strict_types=1);

use Smstark\Weltmarkt\Http\JsonResponse;

require_once dirname(__DIR__, 2) . '/includes/weltmarkt/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    JsonResponse::error('METHOD_NOT_ALLOWED', 'Nur GET-Anfragen sind erlaubt.', 405);
}

function weltmarkt_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
}

try {
    $app = require dirname(__DIR__, 2) . '/includes/weltmarkt/bootstrap.php';
    $metricId = strtolower(trim((string) ($_GET['metric'] ?? '')));

    if (!preg_match('/^[a-z0-9_]{1,96}$/', $metricId)) {
        JsonResponse::error('INVALID_METRIC', 'Die Kennzahl-ID ist ungültig.', 422);
    }

    $metric = $app->metrics()->find($metricId);
    if ($metric === null) {
        JsonResponse::error('METRIC_NOT_FOUND', 'Für diese Kennzahl sind keine gespeicherten Daten vorhanden.', 404);
    }

    $startText = trim((string) ($_GET['start'] ?? $metric['coverageStart']));
    $endText = trim((string) ($_GET['end'] ?? $metric['coverageEnd']));
    $start = weltmarkt_date($startText);
    $end = weltmarkt_date($endText);

    if ($start === null || $end === null || $start > $end) {
        JsonResponse::error('INVALID_RANGE', 'Start und Ende müssen gültige Datumswerte im Format JJJJ-MM-TT sein.', 422);
    }

    if ($start->diff($end)->days > $app->maxRangeDays()) {
        JsonResponse::error('RANGE_TOO_LARGE', 'Der angeforderte Zeitraum ist zu groß.', 422);
    }

    $points = $app->observations()->series($metricId, $startText, $endText);
    JsonResponse::send([
        'success' => true,
        'metric' => $metric,
        'range' => ['start' => $startText, 'end' => $endText],
        'points' => $points,
        'generatedAt' => gmdate(DATE_ATOM),
    ], 200, 300);
} catch (Throwable $exception) {
    error_log('[weltmarkt/series] ' . $exception->getMessage());
    JsonResponse::error('DATABASE_UNAVAILABLE', 'Die gespeicherte Zeitreihe konnte nicht geladen werden.', 503);
}
