<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\ProviderException;
use Smstark\Backtester\Http\RequestSecurity;
use Smstark\Backtester\Providers\CsvImportProvider;
use Smstark\Backtester\Services\Logger;
use Smstark\Backtester\Services\SyncPolicy;
use Smstark\Backtester\Services\ValidationService;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

function preparedCsvOptions(): array
{
    if (RequestSecurity::isCommandLineExecution()) {
        return RequestSecurity::commandLineOptions(['asset', 'from', 'to']);
    }
    return array_intersect_key($_GET, array_flip(['asset', 'from', 'to']));
}

function preparedCsvDate(string $value, string $name): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new RuntimeException('Ungültiges ' . $name . ': erwartet wird JJJJ-MM-TT.');
    }
    return $date;
}

$app = null;
$asset = null;
$sync = null;
$runId = null;
$lockToken = null;
$lockAcquired = false;
$exitCode = 0;
$result = null;

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    RequestSecurity::requireCliOrCronSecret($app->config['security']);
    $options = preparedCsvOptions();
    $slug = (string) ($options['asset'] ?? 'bitcoin');
    if (($options['from'] ?? '') === '' || ($options['to'] ?? '') === '') {
        throw new RuntimeException('--from und --to sind für den vorbereiteten CSV-Import erforderlich.');
    }
    $from = preparedCsvDate((string) $options['from'], 'Startdatum');
    $to = preparedCsvDate((string) $options['to'], 'Enddatum');
    if ($from > $to) {
        throw new RuntimeException('Das Startdatum muss vor oder am Enddatum liegen.');
    }
    $asset = $app->assets()->bySlug($slug);
    if ($asset === null) {
        throw new RuntimeException('Unbekannte Anlageklasse.');
    }

    $sync = $app->syncRepository();
    $runId = $sync->startRun('manual');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lockToken = bin2hex(random_bytes(16));
    $lockAcquired = $sync->acquireLock(
        $asset->id,
        $lockToken,
        $now->modify(sprintf('+%d seconds', (int) $app->config['sync']['lock_seconds'])),
    );
    if (!$lockAcquired) {
        throw new RuntimeException('Die Anlageklasse wird gerade von einem anderen Prozess aktualisiert.');
    }

    $provider = new CsvImportProvider($app->config['providers']['csv']);
    $raw = $provider->fetchRange($asset, $from, $to);
    if ($raw === []) {
        throw new ProviderException('Die vorbereitete CSV enthält im Zeitraum keine Werte.', 'EMPTY_CSV_IMPORT');
    }
    $validated = (new ValidationService())->validate($raw, $asset);
    $counts = $app->observations()->upsertMany($asset->id, $validated);
    $last = end($validated);
    $latest = $last->date->format('Y-m-d');
    $next = SyncPolicy::nextSuccessCheck(
        $now,
        (int) $app->config['sync']['success_check_delay_hours'],
    );
    $sync->markSuccess($asset->id, $latest, $next);
    $stats = [
        'assets_checked' => 1,
        'assets_succeeded' => 1,
        'assets_failed' => 0,
        'rows_inserted' => $counts['inserted'],
        'rows_updated' => $counts['updated'],
    ];
    $sync->finishRun($runId, $stats);
    (new Logger($app->config['sync']['log_file']))->info('Vorbereitete CSV importiert.', [
        'asset' => $asset->slug,
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'inserted' => $counts['inserted'],
        'updated' => $counts['updated'],
        'unchanged' => $counts['unchanged'],
    ]);

    $result = json_encode([
        'success' => true,
        'asset' => $asset->slug,
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
    ] + $counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    $exitCode = 1;
    $result = 'CSV-Import fehlgeschlagen. Details stehen im Server-Log.' . PHP_EOL;
    if ($sync !== null && $runId !== null) {
        $sync->finishRun($runId, [
            'assets_checked' => 1,
            'assets_succeeded' => 0,
            'assets_failed' => 1,
            'rows_inserted' => 0,
            'rows_updated' => 0,
        ], [['asset' => $asset?->slug ?? 'unknown', 'code' => 'CSV_IMPORT_FAILED']]);
    }
    if ($app !== null) {
        (new Logger($app->config['sync']['log_file']))->error('Vorbereiteter CSV-Import fehlgeschlagen.', [
            'asset' => $asset?->slug ?? 'unknown',
            'error' => $error->getMessage(),
        ]);
    }
} finally {
    if ($lockAcquired && $sync !== null && $asset !== null && $lockToken !== null) {
        $sync->releaseLock($asset->id, $lockToken);
    }
}

echo $result;
exit($exitCode);
