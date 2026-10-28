<?php

declare(strict_types=1);

use Smstark\Backtester\Database;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Repositories\ObservationRepository;
use Smstark\Backtester\Repositories\AssetRepository;
use Smstark\Backtester\Repositories\SyncRepository;
use Smstark\Backtester\Tests\Assertions as A;
use Smstark\Backtester\Tests\SkippedTest;

return [
    'MySQL-Upsert, Revision und Lock-Race' => static function (): void {
        $path = getenv('BACKTESTER_TEST_CONFIG');
        if (!$path || !is_file($path)) {
            throw new SkippedTest('BACKTESTER_TEST_CONFIG ist nicht gesetzt.');
        }
        $config = require $path;
        $pdo = Database::connect($config['database']);
        $sourceId = null;
        $assetId = null;
        try {
            $suffix = bin2hex(random_bytes(4));
            $pdo->prepare("INSERT INTO backtester_data_sources (slug, display_name, provider_type) VALUES (?, 'Test', 'csv')")
                ->execute(['test-' . $suffix]);
            $sourceId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO backtester_assets (source_id, slug, display_name, short_name, asset_class, provider_symbol, raw_currency, value_type, native_frequency, calendar_type) VALUES (?, ?, 'Test', 'Test', 'test', 'test', 'EUR', 'close', 'daily', 'calendar_daily')")
                ->execute([$sourceId, 'test-' . $suffix]);
            $assetId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO backtester_sync_state (asset_id) VALUES (?)')->execute([$assetId]);

            $repo = new ObservationRepository($pdo);
            A::same('inserted', $repo->upsert($assetId, new HistoricalObservation(new DateTimeImmutable('2024-01-01'), '100', 'EUR', sourceRevision: 'a')));
            A::same('updated', $repo->upsert($assetId, new HistoricalObservation(new DateTimeImmutable('2024-01-01'), '101', 'EUR', sourceRevision: 'b')));
            $count = $pdo->query('SELECT COUNT(*) FROM backtester_observations WHERE asset_id = ' . $assetId)->fetchColumn();
            A::same(1, (int) $count);

            $sync = new SyncRepository($pdo);
            $until = new DateTimeImmutable('+2 minutes');
            A::true($sync->acquireLock($assetId, '11111111-1111-4111-8111-111111111111', $until));
            A::true(!$sync->acquireLock($assetId, '22222222-2222-4222-8222-222222222222', $until));
            $sync->releaseLock($assetId, '11111111-1111-4111-8111-111111111111');
            A::true($sync->acquireLock($assetId, '22222222-2222-4222-8222-222222222222', $until));
            $sync->markSuccess($assetId, '2024-01-01', new DateTimeImmutable('+1 day'), '2023-12-31');
            $state = $pdo->query(
                'SELECT latest_observation_date, backfill_cursor_date, consecutive_failures '
                . 'FROM backtester_sync_state WHERE asset_id = ' . $assetId
            )->fetch();
            A::same('2024-01-01', $state['latest_observation_date']);
            A::same('2023-12-31', $state['backfill_cursor_date']);
            A::same(0, (int) $state['consecutive_failures']);

            $pdo->prepare('UPDATE backtester_assets SET provider_symbol = ? WHERE id = ?')
                ->execute(['CONFIGURE_TEST_SYMBOL', $assetId]);
            $pdo->prepare('UPDATE backtester_sync_state SET next_check_at = UTC_TIMESTAMP() WHERE asset_id = ?')
                ->execute([$assetId]);
            $dueIds = array_map(
                static fn ($asset): int => $asset->id,
                (new AssetRepository($pdo))->dueForSync(100),
            );
            A::true(!in_array($assetId, $dueIds, true));
        } finally {
            if ($assetId !== null) {
                $pdo->prepare('DELETE FROM backtester_assets WHERE id = ?')->execute([$assetId]);
            }
            if ($sourceId !== null) {
                $pdo->prepare('DELETE FROM backtester_data_sources WHERE id = ?')->execute([$sourceId]);
            }
        }
    },
];
