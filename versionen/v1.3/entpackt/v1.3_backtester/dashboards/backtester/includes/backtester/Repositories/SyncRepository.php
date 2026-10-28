<?php

declare(strict_types=1);

namespace Smstark\Backtester\Repositories;

use DateTimeImmutable;
use PDO;

final class SyncRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function acquireLock(int $assetId, string $token, DateTimeImmutable $until): bool
    {
        $this->pdo->beginTransaction();
        try {
            $ensure = $this->pdo->prepare(
                'INSERT IGNORE INTO backtester_sync_state (asset_id, next_check_at) VALUES (:asset_id, UTC_TIMESTAMP())'
            );
            $ensure->execute(['asset_id' => $assetId]);

            $update = $this->pdo->prepare(<<<'SQL'
UPDATE backtester_sync_state
SET lock_token = :token, lock_until = :lock_until
WHERE asset_id = :asset_id
  AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())
SQL);
            $update->execute([
                'token' => $token,
                'lock_until' => $until->format('Y-m-d H:i:s'),
                'asset_id' => $assetId,
            ]);
            $acquired = $update->rowCount() === 1;
            $this->pdo->commit();
            return $acquired;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function releaseLock(int $assetId, string $token): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE backtester_sync_state
SET lock_token = NULL, lock_until = NULL
WHERE asset_id = :asset_id AND lock_token = :token
SQL);
        $statement->execute(['asset_id' => $assetId, 'token' => $token]);
    }

    public function markSuccess(
        int $assetId,
        string $latestDate,
        DateTimeImmutable $nextCheck,
        ?string $backfillCursor = null
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE backtester_sync_state
SET latest_observation_date = GREATEST(
        COALESCE(latest_observation_date, :latest_date_fallback),
        :latest_date_candidate
    ),
    backfill_cursor_date = COALESCE(:backfill_cursor, backfill_cursor_date),
    last_checked_at = UTC_TIMESTAMP(),
    last_successful_sync_at = UTC_TIMESTAMP(),
    next_check_at = :next_check_at,
    consecutive_failures = 0,
    last_error_at = NULL,
    last_error_code = NULL,
    last_error_message = NULL
WHERE asset_id = :asset_id
SQL);
        $statement->execute([
            'latest_date_fallback' => $latestDate,
            'latest_date_candidate' => $latestDate,
            'backfill_cursor' => $backfillCursor,
            'next_check_at' => $nextCheck->format('Y-m-d H:i:s'),
            'asset_id' => $assetId,
        ]);
    }

    public function markNoData(int $assetId, DateTimeImmutable $nextCheck): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE backtester_sync_state
SET last_checked_at = UTC_TIMESTAMP(),
    last_successful_sync_at = UTC_TIMESTAMP(),
    next_check_at = :next_check_at,
    consecutive_failures = 0,
    last_error_at = NULL,
    last_error_code = NULL,
    last_error_message = NULL
WHERE asset_id = :asset_id
SQL);
        $statement->execute([
            'next_check_at' => $nextCheck->format('Y-m-d H:i:s'),
            'asset_id' => $assetId,
        ]);
    }

    public function markFailure(int $assetId, string $code, string $message, DateTimeImmutable $nextCheck): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE backtester_sync_state
SET last_checked_at = UTC_TIMESTAMP(),
    next_check_at = :next_check_at,
    consecutive_failures = consecutive_failures + 1,
    last_error_at = UTC_TIMESTAMP(),
    last_error_code = :error_code,
    last_error_message = :error_message
WHERE asset_id = :asset_id
SQL);
        $statement->execute([
            'next_check_at' => $nextCheck->format('Y-m-d H:i:s'),
            'error_code' => substr($code, 0, 100),
            'error_message' => mb_substr($message, 0, 1000),
            'asset_id' => $assetId,
        ]);
    }

    public function failureCount(int $assetId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT consecutive_failures FROM backtester_sync_state WHERE asset_id = :asset_id'
        );
        $statement->execute(['asset_id' => $assetId]);
        return (int) ($statement->fetchColumn() ?: 0);
    }

    public function backfillCursor(int $assetId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT backfill_cursor_date FROM backtester_sync_state WHERE asset_id = :asset_id'
        );
        $statement->execute(['asset_id' => $assetId]);
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function startRun(string $triggerType): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO backtester_sync_runs (trigger_type, started_at, status)
VALUES (:trigger_type, UTC_TIMESTAMP(), 'running')
SQL);
        $statement->execute(['trigger_type' => $triggerType]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finishRun(int $runId, array $stats, array $errors = []): void
    {
        $failed = (int) ($stats['assets_failed'] ?? 0);
        $succeeded = (int) ($stats['assets_succeeded'] ?? 0);
        $status = $failed === 0 ? 'success' : ($succeeded > 0 ? 'partial' : 'failed');
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE backtester_sync_runs
SET completed_at = UTC_TIMESTAMP(), status = :status,
    assets_checked = :assets_checked, assets_succeeded = :assets_succeeded,
    assets_failed = :assets_failed, rows_inserted = :rows_inserted,
    rows_updated = :rows_updated, error_summary = :error_summary
WHERE id = :run_id
SQL);
        $statement->execute([
            'status' => $status,
            'assets_checked' => (int) ($stats['assets_checked'] ?? 0),
            'assets_succeeded' => $succeeded,
            'assets_failed' => $failed,
            'rows_inserted' => (int) ($stats['rows_inserted'] ?? 0),
            'rows_updated' => (int) ($stats['rows_updated'] ?? 0),
            'error_summary' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE),
            'run_id' => $runId,
        ]);
    }

    public function pageVisitAllowed(int $limit, int $windowMinutes): bool
    {
        $cutoff = (new DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d minutes', max(1, $windowMinutes)));
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM backtester_sync_runs
WHERE trigger_type = 'page_visit'
  AND started_at >= :cutoff
SQL);
        $statement->execute(['cutoff' => $cutoff->format('Y-m-d H:i:s')]);
        return (int) $statement->fetchColumn() < max(1, $limit);
    }

    public function publicStatus(): array
    {
        $assets = $this->pdo->query(<<<'SQL'
SELECT a.slug, a.display_name, ss.latest_observation_date, ss.last_checked_at,
       ss.last_successful_sync_at, ss.next_check_at, ss.consecutive_failures,
       CASE WHEN ss.last_error_at IS NULL THEN NULL
            ELSE 'Die letzte Aktualisierung war nicht erfolgreich.' END AS public_error
FROM backtester_assets a
LEFT JOIN backtester_sync_state ss ON ss.asset_id = a.id
WHERE a.is_active = 1
ORDER BY a.sort_order
SQL)->fetchAll();

        $lastRun = $this->pdo->query(<<<'SQL'
SELECT completed_at, status
FROM backtester_sync_runs
WHERE status IN ('success', 'partial')
ORDER BY completed_at DESC
LIMIT 1
SQL)->fetch();

        return [
            'latestRun' => $lastRun ?: null,
            'assets' => $assets,
        ];
    }
}
