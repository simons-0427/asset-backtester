<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt\Import;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Weltmarkt\Repositories\SyncRepository;
use RuntimeException;

final class SyncService
{
    private readonly DateTimeZone $utc;

    public function __construct(
        private readonly SyncRepository $repository,
        private readonly JobCatalog $catalog,
        private readonly HttpClient $http,
        private readonly array $config,
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    public function runDue(string $triggerType = 'cron', ?int $maxJobs = null, ?int $budgetSeconds = null): array
    {
        if (!(bool) ($this->config['enabled'] ?? true)) {
            return ['enabled' => false, 'jobs_checked' => 0, 'jobs' => []];
        }

        $limit = max(1, $maxJobs ?? (int) ($this->config['max_jobs_per_run'] ?? 4));
        $budget = max(20, $budgetSeconds ?? (int) ($this->config['max_runtime_seconds'] ?? 700));
        $started = microtime(true);
        $results = [];
        foreach ($this->repository->dueJobKeys($limit) as $jobKey) {
            $remaining = $budget - (int) (microtime(true) - $started);
            if ($remaining < 20) {
                break;
            }
            $results[] = $this->runJob($jobKey, $triggerType, $remaining);
        }

        return [
            'enabled' => true,
            'jobs_checked' => count($results),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'jobs' => $results,
        ];
    }

    public function runJob(string $jobKey, string $triggerType = 'manual', ?int $budgetSeconds = null): array
    {
        $job = $this->catalog->get($jobKey);
        $now = new DateTimeImmutable('now', $this->utc);
        $token = self::uuid();
        $lockSeconds = max(60, (int) ($this->config['lock_seconds'] ?? 900));
        if (!$this->repository->acquireLock($jobKey, $token, $now->modify('+' . $lockSeconds . ' seconds'))) {
            return ['job' => $jobKey, 'status' => 'locked'];
        }

        $runId = null;
        try {
            $runId = $this->repository->startRun($jobKey, $triggerType);
            $from = $now->modify((string) $job['lookback'])->setTime(0, 0);
            $to = $now->setTime(23, 59, 59);
            $collector = new DataCollector(
                $this->http,
                $from,
                $to,
                max(20, ($budgetSeconds ?? (int) ($this->config['max_runtime_seconds'] ?? 700)) - 5),
            );
            $collected = $collector->collect((string) $job['collector']);
            $rows = $collected['rows'];
            if (count($rows) > (int) ($this->config['max_rows_per_job'] ?? 50_000)) {
                throw new RuntimeException('Importjob überschreitet das konfigurierte Zeilenlimit.');
            }

            $counts = $this->repository->upsertObservations($rows);
            $metricIds = array_values(array_unique(array_column($rows, 'metric_id')));
            $this->repository->refreshMetadata($metricIds, $collected['sources']);
            $stats = $counts + [
                'metrics_touched' => count($metricIds),
                'rows_received' => count($rows),
            ];

            if ($collected['errors'] !== []) {
                $next = $this->nextRetry($jobKey, $now);
                $this->repository->finishRun($runId, 'partial', $stats, $collected['errors']);
                $this->repository->markPartial($jobKey, $next, $stats, 'PARTIAL_SOURCE_FAILURE');
                return $this->publicResult($jobKey, 'partial', $stats, $collected['errors'], $next);
            }

            $next = $this->catalog->nextScheduledAt(
                $job,
                $now,
                (string) ($this->config['timezone'] ?? 'Europe/Berlin'),
            );
            $this->repository->finishRun($runId, 'success', $stats, []);
            $this->repository->markSuccess($jobKey, $next, $stats);
            return $this->publicResult($jobKey, 'success', $stats, [], $next);
        } catch (\Throwable $error) {
            $next = $this->nextRetry($jobKey, $now);
            $code = $this->errorCode($error);
            $stats = ['metrics_touched' => 0, 'rows_received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0];
            if ($runId !== null) {
                $this->repository->finishRun($runId, 'failed', $stats, [['source' => $jobKey, 'code' => $code]]);
            }
            $this->repository->markFailure($jobKey, $next, $code, $error->getMessage());
            return $this->publicResult($jobKey, 'failed', $stats, [['source' => $jobKey, 'code' => $code]], $next);
        } finally {
            $this->repository->releaseLock($jobKey, $token);
        }
    }

    private function nextRetry(string $jobKey, DateTimeImmutable $now): DateTimeImmutable
    {
        $failures = $this->repository->failureCount($jobKey) + 1;
        $hours = min(48, 6 * (2 ** min(3, max(0, $failures - 1))));
        return $now->modify('+' . $hours . ' hours');
    }

    private function publicResult(string $job, string $status, array $stats, array $errors, DateTimeImmutable $next): array
    {
        return [
            'job' => $job,
            'status' => $status,
            'metrics_touched' => (int) ($stats['metrics_touched'] ?? 0),
            'rows_received' => (int) ($stats['rows_received'] ?? 0),
            'rows_inserted' => (int) ($stats['inserted'] ?? 0),
            'rows_updated' => (int) ($stats['updated'] ?? 0),
            'rows_unchanged' => (int) ($stats['unchanged'] ?? 0),
            'error_codes' => array_values(array_unique(array_column($errors, 'code'))),
            'source_errors' => array_values(array_map(
                static fn (array $error): array => [
                    'source' => (string) ($error['source'] ?? 'unknown'),
                    'code' => (string) ($error['code'] ?? 'SOURCE_FAILED'),
                ],
                $errors,
            )),
            'next_check_at' => $next->format(DATE_ATOM),
        ];
    }

    private function errorCode(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, 'weltmarkt_sync_jobs') => 'MIGRATION_REQUIRED',
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => 'SOURCE_TIMEOUT',
            str_contains($message, 'database'), str_contains($message, 'sqlstate') => 'DATABASE_ERROR',
            default => 'IMPORT_FAILED',
        };
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
