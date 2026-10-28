<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt\Repositories;

use PDO;

final class MetricRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $sql = <<<'SQL'
SELECT
    m.metric_id,
    m.section,
    m.display_name,
    m.unit,
    m.adjustment,
    m.native_frequency,
    m.calendar_type,
    m.raw_currency,
    m.source_series,
    m.availability_status,
    m.coverage_start,
    m.coverage_end,
    m.observation_count,
    m.methodology_note,
    s.slug AS source_slug,
    s.display_name AS source_name,
    s.provider_type,
    s.base_url AS source_url,
    s.license_note,
    s.retrieved_at,
    o.`value` AS latest_value,
    o.observation_date AS latest_date,
    o.quality_status AS latest_quality,
    p.`value` AS previous_value,
    p.observation_date AS previous_date,
    p.quality_status AS previous_quality,
    c1.`value` AS comparison_1y_value,
    c1.observation_date AS comparison_1y_date,
    c3.`value` AS comparison_3y_value,
    c3.observation_date AS comparison_3y_date,
    c10.`value` AS comparison_10y_value,
    c10.observation_date AS comparison_10y_date,
    cmax.`value` AS comparison_max_value,
    cmax.observation_date AS comparison_max_date
FROM weltmarkt_metrics m
INNER JOIN weltmarkt_data_sources s ON s.id = m.source_id
LEFT JOIN weltmarkt_observations o
    ON o.metric_id = m.metric_id
   AND o.observation_date = m.coverage_end
LEFT JOIN weltmarkt_observations p
    ON p.metric_id = m.metric_id
   AND p.observation_date = (
       SELECT MAX(previous_observation.observation_date)
       FROM weltmarkt_observations previous_observation
       WHERE previous_observation.metric_id = m.metric_id
         AND previous_observation.observation_date < m.coverage_end
   )
LEFT JOIN weltmarkt_observations c1
    ON c1.metric_id = m.metric_id
   AND c1.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date BETWEEN DATE_SUB(m.coverage_end, INTERVAL 1 YEAR) AND m.coverage_end
   )
LEFT JOIN weltmarkt_observations c3
    ON c3.metric_id = m.metric_id
   AND c3.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date BETWEEN DATE_SUB(m.coverage_end, INTERVAL 3 YEAR) AND m.coverage_end
   )
LEFT JOIN weltmarkt_observations c10
    ON c10.metric_id = m.metric_id
   AND c10.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date BETWEEN DATE_SUB(m.coverage_end, INTERVAL 10 YEAR) AND m.coverage_end
   )
LEFT JOIN weltmarkt_observations cmax
    ON cmax.metric_id = m.metric_id
   AND cmax.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date <= m.coverage_end
   )
WHERE m.observation_count > 0
ORDER BY FIELD(
    m.section,
    'leading', 'gdp_nominal', 'gdp_growth', 'trade', 'industry', 'pmi', 'labor', 'inflation', 'consumption', 'realestate',
    'rates', 'cb_balance', 'yields', 'spreads', 'credit',
    'indices', 'commodities', 'sectors', 'valuation', 'volatility', 'fx'
), m.display_name
SQL;

        $rows = $this->pdo->query($sql)->fetchAll();
        return array_map([$this, 'map'], $rows);
    }

    public function find(string $metricId): ?array
    {
        $sql = <<<'SQL'
SELECT
    m.metric_id,
    m.section,
    m.display_name,
    m.unit,
    m.adjustment,
    m.native_frequency,
    m.calendar_type,
    m.raw_currency,
    m.source_series,
    m.availability_status,
    m.coverage_start,
    m.coverage_end,
    m.observation_count,
    m.methodology_note,
    s.slug AS source_slug,
    s.display_name AS source_name,
    s.provider_type,
    s.base_url AS source_url,
    s.license_note,
    s.retrieved_at,
    o.`value` AS latest_value,
    o.observation_date AS latest_date,
    o.quality_status AS latest_quality,
    p.`value` AS previous_value,
    p.observation_date AS previous_date,
    p.quality_status AS previous_quality,
    c1.`value` AS comparison_1y_value,
    c1.observation_date AS comparison_1y_date,
    c3.`value` AS comparison_3y_value,
    c3.observation_date AS comparison_3y_date,
    c10.`value` AS comparison_10y_value,
    c10.observation_date AS comparison_10y_date,
    cmax.`value` AS comparison_max_value,
    cmax.observation_date AS comparison_max_date
FROM weltmarkt_metrics m
INNER JOIN weltmarkt_data_sources s ON s.id = m.source_id
LEFT JOIN weltmarkt_observations o
    ON o.metric_id = m.metric_id
   AND o.observation_date = m.coverage_end
LEFT JOIN weltmarkt_observations p
    ON p.metric_id = m.metric_id
   AND p.observation_date = (
       SELECT MAX(previous_observation.observation_date)
       FROM weltmarkt_observations previous_observation
       WHERE previous_observation.metric_id = m.metric_id
         AND previous_observation.observation_date < m.coverage_end
   )
LEFT JOIN weltmarkt_observations c1
    ON c1.metric_id = m.metric_id
   AND c1.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date BETWEEN DATE_SUB(m.coverage_end, INTERVAL 1 YEAR) AND m.coverage_end
   )
LEFT JOIN weltmarkt_observations c3
    ON c3.metric_id = m.metric_id
   AND c3.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date BETWEEN DATE_SUB(m.coverage_end, INTERVAL 3 YEAR) AND m.coverage_end
   )
LEFT JOIN weltmarkt_observations c10
    ON c10.metric_id = m.metric_id
   AND c10.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date BETWEEN DATE_SUB(m.coverage_end, INTERVAL 10 YEAR) AND m.coverage_end
   )
LEFT JOIN weltmarkt_observations cmax
    ON cmax.metric_id = m.metric_id
   AND cmax.observation_date = (
       SELECT MIN(period_observation.observation_date)
       FROM weltmarkt_observations period_observation
       WHERE period_observation.metric_id = m.metric_id
         AND period_observation.observation_date <= m.coverage_end
   )
WHERE m.metric_id = :metric_id
  AND m.observation_count > 0
LIMIT 1
SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['metric_id' => $metricId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->map($row);
    }

    public function manifest(): array
    {
        $rows = $this->pdo
            ->query('SELECT manifest_key, manifest_value, generated_at FROM weltmarkt_export_manifest ORDER BY manifest_key')
            ->fetchAll();

        $manifest = [];
        foreach ($rows as $row) {
            $manifest[(string) $row['manifest_key']] = (string) $row['manifest_value'];
        }

        return $manifest;
    }

    private function map(array $row): array
    {
        return [
            'id' => (string) $row['metric_id'],
            'section' => (string) $row['section'],
            'name' => (string) $row['display_name'],
            'unit' => (string) $row['unit'],
            'adjustment' => (string) $row['adjustment'],
            'frequency' => (string) $row['native_frequency'],
            'calendarType' => (string) $row['calendar_type'],
            'currency' => $row['raw_currency'] !== null ? (string) $row['raw_currency'] : null,
            'sourceSeries' => $row['source_series'] !== null ? (string) $row['source_series'] : null,
            'availability' => (string) $row['availability_status'],
            'coverageStart' => (string) $row['coverage_start'],
            'coverageEnd' => (string) $row['coverage_end'],
            'observationCount' => (int) $row['observation_count'],
            'methodology' => (string) $row['methodology_note'],
            'source' => [
                'slug' => (string) $row['source_slug'],
                'name' => (string) $row['source_name'],
                'providerType' => (string) $row['provider_type'],
                'url' => $row['source_url'] !== null ? (string) $row['source_url'] : null,
                'licenseNote' => (string) $row['license_note'],
                'retrievedAt' => (string) $row['retrieved_at'],
            ],
            'latest' => $row['latest_date'] === null ? null : [
                'date' => (string) $row['latest_date'],
                'value' => (float) $row['latest_value'],
                'quality' => (string) $row['latest_quality'],
            ],
            'previous' => $row['previous_date'] === null ? null : [
                'date' => (string) $row['previous_date'],
                'value' => (float) $row['previous_value'],
                'quality' => (string) $row['previous_quality'],
            ],
            'comparisons' => [
                '1y' => $this->comparisonPoint($row, '1y'),
                '3y' => $this->comparisonPoint($row, '3y'),
                '10y' => $this->comparisonPoint($row, '10y'),
                'max' => $this->comparisonPoint($row, 'max'),
            ],
        ];
    }

    private function comparisonPoint(array $row, string $period): ?array
    {
        $date = $row['comparison_' . $period . '_date'];
        if ($date === null) {
            return null;
        }

        return [
            'date' => (string) $date,
            'value' => (float) $row['comparison_' . $period . '_value'],
        ];
    }
}
