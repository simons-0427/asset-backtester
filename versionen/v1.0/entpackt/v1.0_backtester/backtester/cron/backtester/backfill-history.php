<?php

declare(strict_types=1);

use Smstark\Backtester\Http\JsonResponse;
use Smstark\Backtester\Http\RequestSecurity;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

function backtesterOptions(): array
{
    if (RequestSecurity::isCommandLineExecution()) {
        return RequestSecurity::commandLineOptions(['asset', 'from', 'to']);
    }
    return array_intersect_key($_GET, array_flip(['asset', 'from', 'to']));
}

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    RequestSecurity::requireCliOrCronSecret($app->config['security']);
    $options = backtesterOptions();
    $assets = isset($options['asset'])
        ? array_filter([$app->assets()->bySlug((string) $options['asset'])])
        : $app->assets()->active();
    if ($assets === []) {
        throw new RuntimeException('Keine passende aktive Anlageklasse gefunden.');
    }

    $to = new DateTimeImmutable((string) ($options['to'] ?? 'yesterday'));
    $aggregate = ['chunks' => 0, 'assets_succeeded' => 0, 'assets_failed' => 0, 'rows_inserted' => 0, 'rows_updated' => 0];

    foreach ($assets as $asset) {
        $explicitFrom = isset($options['from']) && $options['from'] !== '';
        $cursor = $app->syncRepository()->backfillCursor($asset->id);
        if (!$explicitFrom && $cursor !== null) {
            $from = (new DateTimeImmutable($cursor))->modify('+1 day');
        } elseif ($explicitFrom) {
            $from = new DateTimeImmutable((string) $options['from']);
        } elseif ($asset->firstExpectedDate !== null) {
            $from = new DateTimeImmutable($asset->firstExpectedDate);
        } else {
            throw new RuntimeException('Für ' . $asset->slug . ' muss --from oder first_expected_date gesetzt werden.');
        }
        $providerKey = match ($asset->providerType) {
            'coingecko' => 'coingecko',
            'fred' => 'fred',
            default => 'alpha_vantage',
        };
        $chunkDays = (int) ($app->config['providers'][$providerKey]['backfill_chunk_days'] ?? 365);

        for ($chunkStart = $from; $chunkStart <= $to; $chunkStart = $chunkEnd->modify('+1 day')) {
            $candidateEnd = $chunkStart->modify(sprintf('+%d days', max(1, $chunkDays) - 1));
            $chunkEnd = $candidateEnd < $to ? $candidateEnd : $to;
            $result = $app->syncService()->syncAssets([$asset], 'initial_backfill', $chunkStart, $chunkEnd);
            $aggregate['chunks']++;
            $aggregate['assets_succeeded'] += $result['assets_succeeded'];
            $aggregate['assets_failed'] += $result['assets_failed'];
            $aggregate['rows_inserted'] += $result['rows_inserted'];
            $aggregate['rows_updated'] += $result['rows_updated'];
            if ($result['assets_failed'] > 0) {
                break;
            }
        }
    }

    if (RequestSecurity::isCommandLineExecution()) {
        echo json_encode($aggregate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit($aggregate['assets_failed'] > 0 ? 1 : 0);
    }
    JsonResponse::send(['success' => $aggregate['assets_failed'] === 0, 'result' => $aggregate]);
} catch (Throwable $error) {
    error_log('Backtester backfill: ' . $error->getMessage());
    if (RequestSecurity::isCommandLineExecution()) {
        echo "Backfill failed. Details are in the server log.\n";
        exit(1);
    }
    JsonResponse::error('BACKFILL_FAILED', 'Der historische Import ist fehlgeschlagen.', 500);
}
