<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\AssetUnavailableException;
use Smstark\Backtester\Services\BacktestService;
use Smstark\Backtester\Tests\ArrayObservationReader;
use Smstark\Backtester\Tests\Assertions as A;

use function Smstark\Backtester\Tests\assetFixture;

return [
    'Normierung, negative Rendite und Drawdown' => static function (): void {
        $rows = [
            ['observation_date' => '2024-02-29', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-03-01', 'eur_value' => '80', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-03-04', 'eur_value' => '120', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-03-05', 'eur_value' => '60', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries($rows, new DateTimeImmutable('2024-02-29'), new DateTimeImmutable('2024-03-05'), 10000);
        A::near(10000, $result['points'][0]['portfolioValue']);
        A::near(6000, $result['metrics']['finalValue']);
        A::near(-40, $result['metrics']['totalReturnPct']);
        A::near(-50, $result['metrics']['maxDrawdownPct']);
    },
    'Wochenende wird nur im Kalender fortgeführt' => static function (): void {
        $rows = [
            ['observation_date' => '2024-03-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-03-04', 'eur_value' => '110', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries($rows, new DateTimeImmutable('2024-03-01'), new DateTimeImmutable('2024-03-04'), 10000);
        A::same(4, count($result['points']));
        A::true($result['points'][1]['isCarriedForward']);
        A::true($result['points'][2]['isCarriedForward']);
        A::same('2024-03-01', $result['points'][2]['lastRawObservationDate']);
        A::near(11000, $result['points'][3]['portfolioValue']);
    },
    'Bitcoin-Wochenendwert bleibt echte Beobachtung' => static function (): void {
        $rows = [
            ['observation_date' => '2024-03-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-03-02', 'eur_value' => '105', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries($rows, new DateTimeImmutable('2024-03-01'), new DateTimeImmutable('2024-03-02'), 10000);
        A::true(!$result['points'][1]['isCarriedForward']);
        A::near(10500, $result['metrics']['finalValue']);
    },
    'Start vor Datenbeginn ist nicht verfügbar' => static function (): void {
        $rows = [['observation_date' => '2024-03-02', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null]];
        A::throws(
            static fn () => BacktestService::calculateSeries($rows, new DateTimeImmutable('2024-03-01'), new DateTimeImmutable('2024-03-03'), 10000),
            AssetUnavailableException::class
        );
    },
    'Identischer Start- und Endwert ergibt null Rendite' => static function (): void {
        $rows = [['observation_date' => '2024-02-29', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null]];
        $result = BacktestService::calculateSeries($rows, new DateTimeImmutable('2024-02-29'), new DateTimeImmutable('2024-03-01'), 10000);
        A::near(0, $result['metrics']['totalReturnPct']);
        A::near(0, $result['metrics']['cagrPct']);
        A::same(null, $result['metrics']['annualizedVolatilityPct']);
        A::same(null, $result['metrics']['sharpeRatio']);
        A::same(0, $result['metrics']['observedReturnCount']);
    },
    'Volatilität und Tageskennzahlen nutzen nur echte Beobachtungen' => static function (): void {
        $rows = [
            ['observation_date' => '2024-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-01-03', 'eur_value' => '110', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-01-04', 'eur_value' => '99', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-01-08', 'eur_value' => '118.8', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries(
            $rows,
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-08'),
            10000,
            4.0,
        );
        A::near(30.5505, $result['metrics']['annualizedVolatilityPct'], 0.0001);
        A::near(0.8729, $result['metrics']['sharpeRatio'], 0.0001);
        A::near(20, $result['metrics']['bestDayPct']);
        A::same('2024-01-08', $result['metrics']['bestDayDate']);
        A::near(-10, $result['metrics']['worstDayPct']);
        A::same('2024-01-04', $result['metrics']['worstDayDate']);
        A::near(66.6667, $result['metrics']['positiveDaysPct'], 0.0001);
        A::same(3, $result['metrics']['observedReturnCount']);
        A::near(4, $result['metrics']['annualizationPeriods']);
    },
    'Feiertag wird auf der Bewertungsachse fortgeführt' => static function (): void {
        $rows = [
            ['observation_date' => '2024-12-24', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2024-12-26', 'eur_value' => '102', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries($rows, new DateTimeImmutable('2024-12-24'), new DateTimeImmutable('2024-12-26'), 10000);
        A::true($result['points'][1]['isCarriedForward']);
        A::same('2024-12-24', $result['points'][1]['lastRawObservationDate']);
    },
    'CAGR verwendet Kalendertage geteilt durch 365,2425' => static function (): void {
        $rows = [
            ['observation_date' => '2024-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2025-12-31', 'eur_value' => '121', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries($rows, new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2025-12-31'), 10000);
        $expected = (pow(1.21, 1 / (730 / 365.2425)) - 1) * 100;
        A::near($expected, $result['metrics']['cagrPct'], 0.0001);
        A::near(21, $result['metrics']['totalReturnPct']);
    },
    'Partiell fehlendes Asset beendet den Vergleich nicht' => static function (): void {
        $reader = new ArrayObservationReader([
            1 => [
                ['observation_date' => '2024-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
                ['observation_date' => '2024-01-02', 'eur_value' => '110', 'quality_status' => 'valid', 'source_revision' => null],
            ],
            2 => [
                ['observation_date' => '2024-01-02', 'eur_value' => '50', 'quality_status' => 'valid', 'source_revision' => null],
            ],
        ]);
        $service = new BacktestService($reader);
        $result = $service->run(
            [assetFixture(1, 'sp500'), assetFixture(2, 'gold')],
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-02'),
            10000
        );
        A::same(1, count($result['series']));
        A::same('sp500', $result['series'][0]['slug']);
        A::same('gold', $result['warnings'][0]['asset']);
    },
];
