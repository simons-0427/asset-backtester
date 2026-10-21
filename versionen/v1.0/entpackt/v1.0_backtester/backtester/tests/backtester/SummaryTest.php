<?php

declare(strict_types=1);

use Smstark\Backtester\Services\SummaryService;
use Smstark\Backtester\Tests\ArrayObservationReader;
use Smstark\Backtester\Tests\Assertions as A;

use function Smstark\Backtester\Tests\assetFixture;

return [
    'Mehrperiodenwerte verwenden den letzten Wert am oder vor dem Stichtag' => static function (): void {
        $rows = [[
            'observation_date' => '2016-08-01', 'eur_value' => '50', 'quality_status' => 'valid', 'source_revision' => null,
        ]];
        $targets = [
            '2026-07-24' => 99,
            '2026-07-02' => 90,
            '2026-02-02' => 80,
            '2025-08-01' => 75,
            '2023-08-02' => 70,
            '2021-08-02' => 60,
        ];
        foreach ($targets as $date => $value) {
            $rows[] = ['observation_date' => $date, 'eur_value' => (string) $value, 'quality_status' => 'valid', 'source_revision' => null];
        }
        $rows[] = ['observation_date' => '2026-07-31', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null];

        $service = new SummaryService(new ArrayObservationReader([1 => $rows]));
        $summary = $service->build([assetFixture(firstDate: '2016-08-01')], new DateTimeImmutable('2026-08-02'), 10000);
        $periods = $summary['rows'][0]['periods'];
        A::same(['1W', '1M', '6M', '1Y', '3Y', '5Y', '10Y'], array_keys($periods));
        A::same('2026-07-24', $periods['1W']['effectiveStartDate']);
        A::same('2026-07-31', $periods['1W']['effectiveEndDate']);
        A::near(1.0101, $periods['1W']['returnPct'], 0.0001);
        A::near(20000, $periods['10Y']['hypotheticalValue']);
    },
    'Zu kurze Historie liefert Nicht verfügbar statt kürzerem Zeitraum' => static function (): void {
        $rows = [
            ['observation_date' => '2024-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-08-01', 'eur_value' => '120', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $service = new SummaryService(new ArrayObservationReader([1 => $rows]));
        $summary = $service->build([assetFixture(firstDate: '2024-01-01')], new DateTimeImmutable('2026-08-02'));
        A::same(null, $summary['rows'][0]['periods']['3Y']);
        A::same(null, $summary['rows'][0]['periods']['10Y']);
    },
];

