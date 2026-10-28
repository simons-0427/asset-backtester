<?php

declare(strict_types=1);

use Smstark\Backtester\Services\SummaryService;
use Smstark\Backtester\Tests\ArrayDividendYieldReader;
use Smstark\Backtester\Tests\ArrayObservationReader;
use Smstark\Backtester\Tests\Assertions as A;

use function Smstark\Backtester\Tests\assetFixture;

return [
    'Mehrperiodenübersicht berücksichtigt die Dividendenrechnung' => static function (): void {
        $priceRows = [
            ['observation_date' => '2025-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $yieldRows = [];
        for ($month = 1; $month <= 12; $month++) {
            $yieldRows[] = [
                'observation_month' => sprintf('2025-%02d-01', $month),
                'source_observation_date' => sprintf('2025-%02d-28', $month),
                'annual_yield_pct' => '12',
                'is_estimate' => 0,
                'source_revision' => 'test',
            ];
        }
        $service = new SummaryService(
            new ArrayObservationReader([1 => $priceRows]),
            new ArrayDividendYieldReader([1 => $yieldRows]),
            true,
        );
        $summary = $service->build(
            [assetFixture(firstDate: '2025-01-01')],
            new DateTimeImmutable('2026-01-01'),
        );
        $period = $summary['rows'][0]['periods']['1Y'];
        A::same(true, $period['dividendAdjustmentApplied']);
        A::true($period['hypotheticalValue'] > 11190 && $period['hypotheticalValue'] < 11210);
    },
    'MSCI-World-Jahresdividende zählt am 31.12. auch ohne neuen Börsenwert' => static function (): void {
        $priceRows = [
            ['observation_date' => '2016-07-31', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2016-12-30', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $yieldRows = [[
            'observation_month' => '2016-12-01',
            'source_observation_date' => '2016-12-31',
            'annual_yield_pct' => '2.69',
            'is_estimate' => 0,
            'source_revision' => 'manual-annual-schedule-v1',
        ]];
        $service = new SummaryService(
            new ArrayObservationReader([1 => $priceRows]),
            new ArrayDividendYieldReader([1 => $yieldRows]),
            true,
        );
        $summary = $service->build(
            [assetFixture(id: 1, slug: 'msci_world', firstDate: '2016-07-31')],
            new DateTimeImmutable('2016-12-31'),
        );
        $period = $summary['rows'][0]['periods']['1W'];
        A::same(true, $period['dividendAdjustmentApplied']);
        A::near(10269, $period['hypotheticalValue'], 0.01);
        A::same('2016-12-30', $period['effectiveEndDate']);
    },
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
    'Immobilienübersicht addiert den eingegebenen Netto-Mietcashflow' => static function (): void {
        $rows = [
            ['observation_date' => '2025-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-01-01', 'eur_value' => '110', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $service = new SummaryService(new ArrayObservationReader([1 => $rows]));
        $asset = assetFixture(
            id: 1,
            slug: 'us_real_estate',
            firstDate: '2025-01-01',
            rawCurrency: 'PTS',
            displayCurrency: 'PTS',
            nativeFrequency: 'monthly',
            calendarType: 'native_periods',
        );
        $summary = $service->build([$asset], new DateTimeImmutable('2026-01-01'), 10000, 2.0);
        $period = $summary['rows'][0]['periods']['1Y'];
        A::same(true, $period['rentalCashflowAdjustmentApplied']);
        A::true($period['hypotheticalValue'] > 11199 && $period['hypotheticalValue'] < 11201);
    },
    'Deutsche Immobilien verwenden den getrennten Drei-Prozent-Standard' => static function (): void {
        $rows = [
            ['observation_date' => '2025-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-01-01', 'eur_value' => '110', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $service = new SummaryService(new ArrayObservationReader([1 => $rows]));
        $asset = assetFixture(
            id: 1,
            slug: 'de_real_estate',
            firstDate: '2025-01-01',
            rawCurrency: 'PTS',
            displayCurrency: 'PTS',
            nativeFrequency: 'quarterly',
            calendarType: 'native_periods',
        );
        $summary = $service->build([$asset], new DateTimeImmutable('2026-01-01'), 10000, 5.0, 3.0);
        $period = $summary['rows'][0]['periods']['1Y'];
        A::same(true, $period['rentalCashflowAdjustmentApplied']);
        A::near(3.0, $period['annualNetRentalCashflowPct']);
        A::true($period['hypotheticalValue'] > 11299 && $period['hypotheticalValue'] < 11301);
    },
    'DAX-Jahresdividende wird am 31.12. berücksichtigt' => static function (): void {
        $priceRows = [
            ['observation_date' => '2024-12-31', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2025-12-30', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $yieldRows = [[
            'observation_month' => '2025-12-01',
            'source_observation_date' => '2025-12-31',
            'annual_yield_pct' => '2.544',
            'is_estimate' => 0,
            'source_revision' => 'dax-vs-daxk-annual-v1',
        ]];
        $service = new SummaryService(
            new ArrayObservationReader([1 => $priceRows]),
            new ArrayDividendYieldReader([1 => $yieldRows]),
            true,
        );
        $summary = $service->build(
            [assetFixture(id: 1, slug: 'dax', firstDate: '2024-12-31')],
            new DateTimeImmutable('2025-12-31'),
        );
        $period = $summary['rows'][0]['periods']['1Y'];
        A::same(true, $period['dividendAdjustmentApplied']);
        A::near(10254.4, $period['hypotheticalValue'], 0.01);
    },
];
