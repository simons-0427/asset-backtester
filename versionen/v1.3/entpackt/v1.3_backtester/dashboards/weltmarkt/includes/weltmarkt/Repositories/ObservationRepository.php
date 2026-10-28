<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt\Repositories;

use PDO;

final class ObservationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function series(string $metricId, string $start, string $end): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT observation_date, `value`, quality_status, source_revision
FROM weltmarkt_observations
WHERE metric_id = :metric_id
  AND observation_date BETWEEN :start_date AND :end_date
ORDER BY observation_date
SQL);
        $statement->execute([
            'metric_id' => $metricId,
            'start_date' => $start,
            'end_date' => $end,
        ]);

        return array_map(static fn (array $row): array => [
            'date' => (string) $row['observation_date'],
            'value' => (float) $row['value'],
            'quality' => (string) $row['quality_status'],
            'revision' => (string) $row['source_revision'],
        ], $statement->fetchAll());
    }

    public function databaseStatus(): array
    {
        $row = $this->pdo->query(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM weltmarkt_metrics WHERE observation_count > 0) AS metric_count,
    (SELECT COUNT(*) FROM weltmarkt_observations) AS observation_count,
    (SELECT MAX(observation_date) FROM weltmarkt_observations) AS latest_observation
SQL)->fetch();

        return [
            'metricCount' => (int) $row['metric_count'],
            'observationCount' => (int) $row['observation_count'],
            'latestObservation' => (string) $row['latest_observation'],
        ];
    }
}
