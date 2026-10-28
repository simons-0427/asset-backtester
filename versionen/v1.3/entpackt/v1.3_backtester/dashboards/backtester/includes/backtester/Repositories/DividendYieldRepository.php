<?php

declare(strict_types=1);

namespace Smstark\Backtester\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Smstark\Backtester\Contracts\DividendYieldReaderInterface;
use Smstark\Backtester\Domain\DividendYieldObservation;

final class DividendYieldRepository implements DividendYieldReaderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function rangeWithStartAnchor(
        int $assetId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $startMonth = $start->format('Y-m-01');
        $endMonth = $end->format('Y-m-01');
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT observation_month, source_observation_date, annual_yield_pct, is_estimate, source_revision
FROM backtester_dividend_yields
WHERE asset_id = :asset_id
  AND observation_month BETWEEN :start_month AND :end_month
UNION ALL
SELECT observation_month, source_observation_date, annual_yield_pct, is_estimate, source_revision
FROM backtester_dividend_yields
WHERE asset_id = :anchor_asset_id
  AND observation_month = (
      SELECT MAX(observation_month)
      FROM backtester_dividend_yields
      WHERE asset_id = :sub_asset_id AND observation_month < :anchor_month
  )
ORDER BY observation_month
SQL);
        $statement->execute([
            'asset_id' => $assetId,
            'start_month' => $startMonth,
            'end_month' => $endMonth,
            'anchor_asset_id' => $assetId,
            'sub_asset_id' => $assetId,
            'anchor_month' => $startMonth,
        ]);
        return $statement->fetchAll();
    }

    public function upsert(int $assetId, DividendYieldObservation $observation): string
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO backtester_dividend_yields (
    asset_id, observation_month, source_observation_date, annual_yield_pct,
    is_estimate, source_url, source_revision, raw_payload_hash, fetched_at
) VALUES (
    :asset_id, :observation_month, :source_observation_date, :annual_yield_pct,
    :is_estimate, :source_url, :source_revision, :raw_payload_hash, UTC_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE
    annual_yield_pct = IF(
        VALUES(source_observation_date) >= source_observation_date,
        VALUES(annual_yield_pct), annual_yield_pct
    ),
    is_estimate = IF(
        VALUES(source_observation_date) >= source_observation_date,
        VALUES(is_estimate), is_estimate
    ),
    source_url = IF(
        VALUES(source_observation_date) >= source_observation_date,
        VALUES(source_url), source_url
    ),
    source_revision = IF(
        VALUES(source_observation_date) >= source_observation_date,
        VALUES(source_revision), source_revision
    ),
    raw_payload_hash = IF(
        VALUES(source_observation_date) >= source_observation_date,
        VALUES(raw_payload_hash), raw_payload_hash
    ),
    source_observation_date = GREATEST(source_observation_date, VALUES(source_observation_date)),
    fetched_at = UTC_TIMESTAMP()
SQL);
        $statement->execute($observation->toStorageArray($assetId));
        return match ($statement->rowCount()) {
            1 => 'inserted',
            2 => 'updated',
            default => 'unchanged',
        };
    }

    /** @param DividendYieldObservation[] $observations */
    public function upsertMany(int $assetId, array $observations): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        $this->pdo->beginTransaction();
        try {
            foreach ($observations as $observation) {
                if (!$observation instanceof DividendYieldObservation) {
                    throw new RuntimeException('Ungültige Dividendenbeobachtung.');
                }
                $counts[$this->upsert($assetId, $observation)]++;
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

    public function isDue(string $sourceSlug, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT next_check_at FROM backtester_auxiliary_sync_state WHERE source_slug = :source_slug'
        );
        $statement->execute(['source_slug' => $sourceSlug]);
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            return true;
        }
        return new DateTimeImmutable($value, new DateTimeZone('UTC')) <= $now;
    }

    public function failureCount(string $sourceSlug): int
    {
        $statement = $this->pdo->prepare(
            'SELECT consecutive_failures FROM backtester_auxiliary_sync_state WHERE source_slug = :source_slug'
        );
        $statement->execute(['source_slug' => $sourceSlug]);
        return (int) ($statement->fetchColumn() ?: 0);
    }

    public function markSuccess(string $sourceSlug, string $latestMonth, DateTimeImmutable $nextCheck): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO backtester_auxiliary_sync_state (
    source_slug, latest_observation_month, last_checked_at,
    last_successful_sync_at, next_check_at, consecutive_failures
) VALUES (
    :source_slug, :latest_month, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :next_check_at, 0
)
ON DUPLICATE KEY UPDATE
    latest_observation_month = GREATEST(
        COALESCE(latest_observation_month, VALUES(latest_observation_month)),
        VALUES(latest_observation_month)
    ),
    last_checked_at = UTC_TIMESTAMP(),
    last_successful_sync_at = UTC_TIMESTAMP(),
    next_check_at = VALUES(next_check_at),
    consecutive_failures = 0,
    last_error_at = NULL,
    last_error_code = NULL,
    last_error_message = NULL
SQL);
        $statement->execute([
            'source_slug' => $sourceSlug,
            'latest_month' => $latestMonth,
            'next_check_at' => $nextCheck->format('Y-m-d H:i:s'),
        ]);
    }

    public function markFailure(
        string $sourceSlug,
        string $code,
        string $message,
        DateTimeImmutable $nextCheck,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO backtester_auxiliary_sync_state (
    source_slug, last_checked_at, next_check_at, consecutive_failures,
    last_error_at, last_error_code, last_error_message
) VALUES (
    :source_slug, UTC_TIMESTAMP(), :next_check_at, 1,
    UTC_TIMESTAMP(), :error_code, :error_message
)
ON DUPLICATE KEY UPDATE
    last_checked_at = UTC_TIMESTAMP(),
    next_check_at = VALUES(next_check_at),
    consecutive_failures = consecutive_failures + 1,
    last_error_at = UTC_TIMESTAMP(),
    last_error_code = VALUES(last_error_code),
    last_error_message = VALUES(last_error_message)
SQL);
        $statement->execute([
            'source_slug' => $sourceSlug,
            'next_check_at' => $nextCheck->format('Y-m-d H:i:s'),
            'error_code' => substr($code, 0, 100),
            'error_message' => mb_substr($message, 0, 1000),
        ]);
    }

    public function publicStatus(string $sourceSlug): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT source_slug, latest_observation_month, last_checked_at,
       last_successful_sync_at, next_check_at, consecutive_failures,
       CASE WHEN last_error_at IS NULL THEN NULL
            ELSE 'Die letzte Dividendenaktualisierung war nicht erfolgreich.' END AS public_error
FROM backtester_auxiliary_sync_state
WHERE source_slug = :source_slug
SQL);
        $statement->execute(['source_slug' => $sourceSlug]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}
