<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt\Repositories;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SyncRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function dueJobKeys(int $limit): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT job_key
FROM weltmarkt_sync_jobs
WHERE enabled = 1
  AND next_check_at <= UTC_TIMESTAMP()
  AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())
ORDER BY next_check_at, job_key
LIMIT :job_limit
SQL);
        $statement->bindValue('job_limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): string => (string) $row['job_key'], $statement->fetchAll());
    }

    public function acquireLock(string $jobKey, string $token, DateTimeImmutable $until): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE weltmarkt_sync_jobs
SET lock_token = :token,
    lock_until = :lock_until,
    status = 'running',
    last_attempt_at = UTC_TIMESTAMP()
WHERE job_key = :job_key
  AND enabled = 1
  AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())
SQL);
        $statement->execute([
            'token' => $token,
            'lock_until' => $until->format('Y-m-d H:i:s'),
            'job_key' => $jobKey,
        ]);

        return $statement->rowCount() === 1;
    }

    public function releaseLock(string $jobKey, string $token): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE weltmarkt_sync_jobs
SET lock_token = NULL, lock_until = NULL
WHERE job_key = :job_key AND lock_token = :token
SQL);
        $statement->execute(['job_key' => $jobKey, 'token' => $token]);
    }

    public function startRun(string $jobKey, string $triggerType): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO weltmarkt_sync_runs (job_key, trigger_type, status, started_at)
VALUES (:job_key, :trigger_type, 'running', UTC_TIMESTAMP())
SQL);
        $statement->execute(['job_key' => $jobKey, 'trigger_type' => $triggerType]);
        return (int) $this->pdo->lastInsertId();
    }

    public function upsertObservations(array $rows): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        if ($rows === []) {
            return $counts;
        }

        $existing = $this->pdo->prepare(<<<'SQL'
SELECT raw_payload_hash
FROM weltmarkt_observations
WHERE metric_id = :metric_id AND observation_date = :observation_date
LIMIT 1
SQL);
        $upsert = $this->pdo->prepare(<<<'SQL'
INSERT INTO weltmarkt_observations
    (metric_id, observation_date, `value`, source_timestamp, fetched_at, quality_status, source_revision, raw_payload_hash)
VALUES
    (:metric_id, :observation_date, :value, :source_timestamp, UTC_TIMESTAMP(), :quality_status, :source_revision, :raw_payload_hash)
ON DUPLICATE KEY UPDATE
    `value` = VALUES(`value`),
    source_timestamp = VALUES(source_timestamp),
    fetched_at = VALUES(fetched_at),
    quality_status = VALUES(quality_status),
    source_revision = VALUES(source_revision),
    raw_payload_hash = VALUES(raw_payload_hash)
SQL);

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $existing->execute([
                    'metric_id' => $row['metric_id'],
                    'observation_date' => $row['date'],
                ]);
                $oldHash = $existing->fetchColumn();
                if (is_string($oldHash) && hash_equals($oldHash, $row['hash'])) {
                    $counts['unchanged']++;
                    continue;
                }

                $upsert->execute([
                    'metric_id' => $row['metric_id'],
                    'observation_date' => $row['date'],
                    'value' => $this->decimal($row['value']),
                    'source_timestamp' => $row['source_timestamp'],
                    'quality_status' => $row['quality'],
                    'source_revision' => $row['revision'],
                    'raw_payload_hash' => $row['hash'],
                ]);
                if ($oldHash === false) {
                    $counts['inserted']++;
                } else {
                    $counts['updated']++;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $counts;
    }

    public function refreshMetadata(array $metricIds, array $sourceSlugs): void
    {
        $metricIds = array_values(array_unique(array_filter($metricIds, [$this, 'validIdentifier'])));
        $sourceSlugs = array_values(array_unique(array_filter($sourceSlugs, [$this, 'validIdentifier'])));

        $this->pdo->beginTransaction();
        try {
            $metricUpdate = $this->pdo->prepare(<<<'SQL'
UPDATE weltmarkt_metrics m
SET m.coverage_start = (SELECT MIN(o.observation_date) FROM weltmarkt_observations o WHERE o.metric_id = m.metric_id),
    m.coverage_end = (SELECT MAX(o.observation_date) FROM weltmarkt_observations o WHERE o.metric_id = m.metric_id),
    m.observation_count = (SELECT COUNT(*) FROM weltmarkt_observations o WHERE o.metric_id = m.metric_id),
    m.availability_status = CASE
        WHEN (SELECT COUNT(*) FROM weltmarkt_observations o WHERE o.metric_id = m.metric_id) = 0 THEN 'unavailable'
        WHEN (SELECT MAX(o.observation_date) FROM weltmarkt_observations o WHERE o.metric_id = m.metric_id) >= UTC_DATE() - INTERVAL 45 DAY THEN 'ready'
        ELSE 'partial'
    END
WHERE m.metric_id = :metric_id
SQL);
            $qualityUpdate = $this->pdo->prepare(<<<'SQL'
UPDATE weltmarkt_data_quality q
INNER JOIN weltmarkt_metrics m ON m.metric_id = q.metric_id
SET q.expected_end = GREATEST(q.expected_end, COALESCE(m.coverage_end, q.expected_end)),
    q.observation_count = m.observation_count,
    q.valid_value_count = (SELECT COUNT(*) FROM weltmarkt_observations o WHERE o.metric_id = m.metric_id AND o.quality_status <> 'flagged'),
    q.coverage_start = m.coverage_start,
    q.coverage_end = m.coverage_end,
    q.availability_status = m.availability_status,
    q.checked_at = UTC_TIMESTAMP()
WHERE q.metric_id = :metric_id
SQL);
            foreach ($metricIds as $metricId) {
                $metricUpdate->execute(['metric_id' => $metricId]);
                $qualityUpdate->execute(['metric_id' => $metricId]);
            }

            if ($sourceSlugs !== []) {
                $sourceUpdate = $this->pdo->prepare(
                    'UPDATE weltmarkt_data_sources SET retrieved_at = UTC_TIMESTAMP() WHERE slug = :slug'
                );
                foreach ($sourceSlugs as $slug) {
                    $sourceUpdate->execute(['slug' => $slug]);
                }
            }

            $summary = $this->pdo->query(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM weltmarkt_metrics WHERE observation_count > 0) AS metric_count,
    (SELECT COUNT(*) FROM weltmarkt_observations) AS observation_count,
    (SELECT COUNT(*) FROM weltmarkt_metrics WHERE availability_status = 'ready' AND observation_count > 0) AS ready_count,
    (SELECT COUNT(*) FROM weltmarkt_metrics WHERE availability_status = 'partial' AND observation_count > 0) AS partial_count,
    (SELECT COUNT(*) FROM weltmarkt_metrics WHERE availability_status = 'unavailable') AS unavailable_count,
    (SELECT COUNT(*) FROM weltmarkt_data_sources WHERE slug NOT IN ('unavailable', 'derived-metrics')) AS source_count,
    (SELECT MIN(observation_date) FROM weltmarkt_observations) AS start_date,
    (SELECT MAX(observation_date) FROM weltmarkt_observations) AS end_date
SQL)->fetch();
            if (!is_array($summary)) {
                throw new RuntimeException('Import-Metadaten konnten nicht berechnet werden.');
            }

            $manifest = [
                'retrieved_at' => gmdate('Y-m-d H:i:s'),
                'metric_count' => (string) $summary['metric_count'],
                'observation_count' => (string) $summary['observation_count'],
                'ready_metric_count' => (string) $summary['ready_count'],
                'partial_metric_count' => (string) $summary['partial_count'],
                'unavailable_metric_count' => (string) $summary['unavailable_count'],
                'source_count' => (string) $summary['source_count'],
                'start_date' => (string) $summary['start_date'],
                'end_date' => (string) $summary['end_date'],
            ];
            $manifestUpsert = $this->pdo->prepare(<<<'SQL'
INSERT INTO weltmarkt_export_manifest (manifest_key, manifest_value, generated_at)
VALUES (:manifest_key, :manifest_value, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE manifest_value = VALUES(manifest_value), generated_at = VALUES(generated_at)
SQL);
            foreach ($manifest as $key => $value) {
                $manifestUpsert->execute(['manifest_key' => $key, 'manifest_value' => $value]);
            }

            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function finishRun(int $runId, string $status, array $stats, array $errors): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE weltmarkt_sync_runs
SET status = :status,
    finished_at = UTC_TIMESTAMP(),
    metrics_touched = :metrics_touched,
    rows_received = :rows_received,
    rows_inserted = :rows_inserted,
    rows_updated = :rows_updated,
    rows_unchanged = :rows_unchanged,
    error_count = :error_count,
    error_summary = :error_summary
WHERE id = :run_id
SQL);
        $statement->execute([
            'status' => $status,
            'metrics_touched' => $stats['metrics_touched'] ?? 0,
            'rows_received' => $stats['rows_received'] ?? 0,
            'rows_inserted' => $stats['inserted'] ?? 0,
            'rows_updated' => $stats['updated'] ?? 0,
            'rows_unchanged' => $stats['unchanged'] ?? 0,
            'error_count' => count($errors),
            'error_summary' => $errors === [] ? null : json_encode(array_slice($errors, 0, 20), JSON_UNESCAPED_SLASHES),
            'run_id' => $runId,
        ]);
    }

    public function markSuccess(string $jobKey, DateTimeImmutable $nextCheck, array $stats): void
    {
        $this->markJob($jobKey, 'success', $nextCheck, $stats, null, null, true);
    }

    public function markPartial(string $jobKey, DateTimeImmutable $nextCheck, array $stats, string $code): void
    {
        $this->markJob($jobKey, 'partial', $nextCheck, $stats, $code, 'Mindestens eine Datenquelle ist fehlgeschlagen.', false);
    }

    public function markFailure(string $jobKey, DateTimeImmutable $nextCheck, string $code, string $message): void
    {
        $this->markJob($jobKey, 'failed', $nextCheck, [], $code, $message, false);
    }

    public function failureCount(string $jobKey): int
    {
        $statement = $this->pdo->prepare('SELECT consecutive_failures FROM weltmarkt_sync_jobs WHERE job_key = :job_key');
        $statement->execute(['job_key' => $jobKey]);
        return (int) $statement->fetchColumn();
    }

    public function publicStatus(): array
    {
        $jobs = $this->pdo->query(<<<'SQL'
SELECT job_key, label, frequency, status, last_attempt_at, last_success_at, next_check_at,
       consecutive_failures, last_error_code, last_rows_inserted, last_rows_updated
FROM weltmarkt_sync_jobs
WHERE enabled = 1
ORDER BY FIELD(frequency, 'daily', 'weekly', 'monthly'), job_key
SQL)->fetchAll();

        return array_map(static fn (array $row): array => [
            'job' => (string) $row['job_key'],
            'label' => (string) $row['label'],
            'frequency' => (string) $row['frequency'],
            'status' => (string) $row['status'],
            'lastAttemptAt' => $row['last_attempt_at'] !== null ? (string) $row['last_attempt_at'] . 'Z' : null,
            'lastSuccessAt' => $row['last_success_at'] !== null ? (string) $row['last_success_at'] . 'Z' : null,
            'nextCheckAt' => (string) $row['next_check_at'] . 'Z',
            'consecutiveFailures' => (int) $row['consecutive_failures'],
            'lastErrorCode' => $row['last_error_code'] !== null ? (string) $row['last_error_code'] : null,
            'lastRowsInserted' => (int) $row['last_rows_inserted'],
            'lastRowsUpdated' => (int) $row['last_rows_updated'],
        ], $jobs);
    }

    private function markJob(
        string $jobKey,
        string $status,
        DateTimeImmutable $nextCheck,
        array $stats,
        ?string $errorCode,
        ?string $errorMessage,
        bool $success,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE weltmarkt_sync_jobs
SET status = :status,
    last_success_at = CASE WHEN :is_success = 1 THEN UTC_TIMESTAMP() ELSE last_success_at END,
    next_check_at = :next_check_at,
    consecutive_failures = CASE WHEN :is_success_reset = 1 THEN 0 ELSE consecutive_failures + 1 END,
    last_error_code = :error_code,
    last_error_message = :error_message,
    last_rows_inserted = :rows_inserted,
    last_rows_updated = :rows_updated,
    last_rows_unchanged = :rows_unchanged
WHERE job_key = :job_key
SQL);
        $statement->execute([
            'status' => $status,
            'is_success' => $success ? 1 : 0,
            'next_check_at' => $nextCheck->format('Y-m-d H:i:s'),
            'is_success_reset' => $success ? 1 : 0,
            'error_code' => $errorCode,
            'error_message' => $errorMessage !== null ? substr($errorMessage, 0, 1000) : null,
            'rows_inserted' => $stats['inserted'] ?? 0,
            'rows_updated' => $stats['updated'] ?? 0,
            'rows_unchanged' => $stats['unchanged'] ?? 0,
            'job_key' => $jobKey,
        ]);
    }

    private function validIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9_-]{1,96}$/', $value) === 1;
    }

    private function decimal(float|int|string $value): string
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new RuntimeException('Ungültiger Zahlenwert im Import.');
        }

        $decimal = rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
        return $decimal === '' || $decimal === '-0' ? '0' : $decimal;
    }
}
