<?php

declare(strict_types=1);

use Smstark\Backtester\Services\BacktestService;
use Smstark\Backtester\Services\DividendAdjustment;
use Smstark\Backtester\Services\TaxAdjustment;
use Smstark\Backtester\Services\TerAdjustment;
use Smstark\Backtester\Tests\ArrayObservationReader;
use Smstark\Backtester\Tests\Assertions as A;
use function Smstark\Backtester\Tests\assetFixture;

return [
    'TER wird zeitanteilig auf den Verlauf angewendet' => static function (): void {
        $start = new DateTimeImmutable('2025-01-01');
        $end = new DateTimeImmutable('2026-01-01');
        A::near(pow(0.99, 365 / 365.2425), TerAdjustment::factor($start, $end, 1.0), 0.0000001);

        $rows = [
            ['observation_date' => '2025-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries(
            $rows,
            $start,
            $end,
            10000,
            252,
            [],
            DividendAdjustment::METHOD,
            0.0,
            [],
            null,
            1.0,
            'sp500',
        );
        A::near(10000 * pow(0.99, 365 / 365.2425), $result['metrics']['finalValue'], 0.01);
        A::near($result['metrics']['finalValue'], $result['points'][array_key_last($result['points'])]['priceOnlyPortfolioValue'], 0.01);
        A::same(true, $result['terAdjustment']['applied']);
        A::true($result['metrics']['estimatedTerCost'] > 99.0);
    },
    'TER kann nach Aktivierung auch Bitcoin Gold und Immobilien belasten' => static function (): void {
        $rows = [
            ['observation_date' => '2025-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $reader = new ArrayObservationReader([1 => $rows, 2 => $rows, 3 => $rows, 4 => $rows]);
        $service = new BacktestService($reader);
        $result = $service->run(
            [
                assetFixture(1, 'bitcoin'),
                assetFixture(2, 'gold'),
                assetFixture(3, 'de_real_estate'),
                assetFixture(4, 'us_real_estate'),
            ],
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2026-01-01'),
            10000,
            0.0,
            0.0,
            null,
            null,
            1.0,
        );

        A::same(4, count($result['series']));
        foreach ($result['series'] as $series) {
            A::same(true, $series['terAdjustment']['applied']);
            A::true($series['metrics']['finalValue'] < 10000);
        }
    },
    'Aktien-ETF-Steuer berücksichtigt Teilfreistellung Pauschbetrag und Soli' => static function (): void {
        $result = TaxAdjustment::calculate(
            'sp500',
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2026-01-01'),
            10000,
            20000,
            [
                'enabled' => true,
                'personalIncomeTaxPct' => 42,
                'capitalAllowance' => 1000,
                'churchTaxPct' => 0,
            ],
        );
        A::near(1582.5, $result['taxAmount'], 0.0001);
        A::near(18417.5, $result['afterTaxValue'], 0.0001);
        A::same('capital_income_tax', $result['status']);
    },
    'Bitcoin-Steuer wechselt innerhalb des Charts nach einem Jahr' => static function (): void {
        $settings = [
            'enabled' => true,
            'personalIncomeTaxPct' => 42,
            'capitalAllowance' => 1000,
            'churchTaxPct' => 0,
        ];
        $taxable = TaxAdjustment::calculate(
            'bitcoin',
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2025-12-31'),
            10000,
            12000,
            $settings,
        );
        A::near(840, $taxable['taxAmount']);
        A::same('taxable_private_sale', $taxable['status']);

        $taxFree = TaxAdjustment::calculate(
            'bitcoin',
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2026-01-01'),
            10000,
            12000,
            $settings,
        );
        A::near(0, $taxFree['taxAmount']);
        A::same('tax_free_holding_period', $taxFree['status']);
    },
    'Backtest zeigt reale Nachsteuerrendite und Steuerstatus an jedem Charttag' => static function (): void {
        $rows = [
            ['observation_date' => '2025-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2025-06-30', 'eur_value' => '120', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-01-01', 'eur_value' => '120', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $inflationRows = [
            ['observation_date' => '2025-01-01', 'eur_value' => '100', 'quality_status' => 'valid', 'source_revision' => null],
            ['observation_date' => '2026-01-01', 'eur_value' => '110', 'quality_status' => 'valid', 'source_revision' => null],
        ];
        $result = BacktestService::calculateSeries(
            $rows,
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2026-01-01'),
            10000,
            365.2425,
            [],
            DividendAdjustment::METHOD,
            0.0,
            $inflationRows,
            null,
            0.0,
            'bitcoin',
            [
                'enabled' => true,
                'personalIncomeTaxPct' => 42,
                'capitalAllowance' => 1000,
                'churchTaxPct' => 0,
            ],
        );
        $insideOneYear = $result['points'][180];
        A::same('taxable_private_sale', $insideOneYear['taxStatus']);
        A::near(840, $insideOneYear['taxAmount']);
        A::same('tax_free_holding_period', $result['points'][array_key_last($result['points'])]['taxStatus']);
        A::near(12000 / 1.1, $result['metrics']['finalValue'], 0.01);
        A::same(true, $result['metrics']['inflationAdjustmentApplied']);
        A::same(true, $result['metrics']['taxAdjustmentApplied']);
    },
];
