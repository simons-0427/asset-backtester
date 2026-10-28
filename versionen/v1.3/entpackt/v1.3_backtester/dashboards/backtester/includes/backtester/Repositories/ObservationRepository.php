<?php

declare(strict_types=1);

namespace Smstark\Backtester\Repositories;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Smstark\Backtester\Contracts\ObservationReaderInterface;
use Smstark\Backtester\Domain\HistoricalObservation;

final class ObservationRepository implements ObservationReaderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function seriesWithStartAnchor(int $assetId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT observation_date, eur_value, quality_status, source_revision
FROM backtester_observations
WHERE asset_id = :asset_id
  AND observation_date BETWEEN :start_date AND :end_date
UNION ALL
SELECT observation_date, eur_value, quality_status, source_revision
FROM backtester_observations
WHERE asset_id = :anchor_asset_id
  AND observation_date = (
      SELECT MAX(observation_date)
      FROM backtester_observations
      WHERE asset_id = :sub_asset_id AND observation_date < :anchor_date
  )
ORDER BY observation_date
SQL);
        $statement->execute([
            'asset_id' => $assetId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'anchor_asset_id' => $assetId,
            'sub_asset_id' => $assetId,
            'anchor_date' => $start->format('Y-m-d'),
        ]);
        return $statement->fetchAll();
    }

    public function range(int $assetId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT observation_date, eur_value, quality_status, source_revision
FROM backtester_observations
WHERE asset_id = :asset_id
  AND observation_date BETWEEN :start_date AND :end_date
ORDER BY observation_date
SQL);
        $statement->execute([
            'asset_id' => $assetId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);
        return $statement->fetchAll();
    }

    public function valueAtOrBefore(int $assetId, DateTimeImmutable $date): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT observation_date, eur_value, quality_status, source_revision
FROM backtester_observations
WHERE asset_id = :asset_id AND observation_date <= :date
ORDER BY observation_date DESC
LIMIT 1
SQL);
        $statement->execute(['asset_id' => $assetId, 'date' => $date->format('Y-m-d')]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function upsert(int $assetId, HistoricalObservation $observation): string
    {
        $row = $observation->toStorageArray($assetId);
        if (
            $row['raw_currency'] !== 'EUR'
            && ($row['eur_value'] === null || $row['fx_rate_to_eur'] === null || $row['fx_observation_date'] === null)
        ) {
            throw new RuntimeException('Für Nicht-EUR-Reihen muss vor dem Speichern eine historische FX-Umrechnung erfolgen.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO backtester_observations (
    asset_id, observation_date, raw_value, raw_currency, eur_value,
    fx_rate_to_eur, fx_observation_date, source_timestamp, fetched_at,
    quality_status, source_revision, raw_payload_hash
) VALUES (
    :asset_id, :observation_date, :raw_value, :raw_currency, :eur_value,
    :fx_rate_to_eur, :fx_observation_date, :source_timestamp, UTC_TIMESTAMP(),
    :quality_status, :source_revision, :raw_payload_hash
)
ON DUPLICATE KEY UPDATE
    quality_status = CASE
        WHEN NOT(raw_value <=> VALUES(raw_value))
          OR NOT(raw_currency <=> VALUES(raw_currency))
          OR NOT(eur_value <=> VALUES(eur_value))
          OR NOT(fx_rate_to_eur <=> VALUES(fx_rate_to_eur))
          OR NOT(fx_observation_date <=> VALUES(fx_observation_date))
          OR NOT(source_revision <=> VALUES(source_revision)) THEN 'revised'
        ELSE VALUES(quality_status)
    END,
    raw_value = VALUES(raw_value),
    raw_currency = VALUES(raw_currency),
    eur_value = VALUES(eur_value),
    fx_rate_to_eur = VALUES(fx_rate_to_eur),
    fx_observation_date = VALUES(fx_observation_date),
    source_timestamp = VALUES(source_timestamp),
    fetched_at = UTC_TIMESTAMP(),
    source_revision = VALUES(source_revision),
    raw_payload_hash = VALUES(raw_payload_hash)
SQL);
        $statement->execute($row);
        return match ($statement->rowCount()) {
            1 => 'inserted',
            2 => 'updated',
            default => 'unchanged',
        };
    }

    public function upsertMany(int $assetId, array $observations): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        $this->pdo->beginTransaction();
        try {
            foreach ($observations as $observation) {
                if (!$observation instanceof HistoricalObservation) {
                    throw new RuntimeException('Ungültiges Beobachtungsobjekt.');
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

    public function weekdayGaps(int $assetId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->range($assetId, $from, $to);
        $known = array_fill_keys(array_column($rows, 'observation_date'), true);
        $gaps = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $weekday = (int) $day->format('N');
            if ($weekday <= 5 && !isset($known[$day->format('Y-m-d')])) {
                $gaps[] = $day->format('Y-m-d');
            }
        }
        return $gaps;
    }
}
