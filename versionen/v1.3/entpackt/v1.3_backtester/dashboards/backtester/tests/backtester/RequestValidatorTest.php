<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\ValidationException;
use Smstark\Backtester\Http\RequestValidator;
use Smstark\Backtester\Tests\Assertions as A;

$config = [
    'max_assets_per_request' => 3,
    'max_range_days' => 14610,
    'min_amount' => 100,
    'max_amount' => 10000000,
    'default_de_net_rental_cashflow_pct' => 3.0,
];

return [
    'API-Parameter werden normalisiert' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        $result = $validator->series([
            'assets' => 'sp500,bitcoin,sp500',
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'amount' => '10000',
        ], ['sp500', 'bitcoin', 'gold']);
        A::same(['sp500', 'bitcoin'], $result['assets']);
        A::near(10000, $result['amount']);
        A::near(5.0, $result['netRentalCashflowPct']);
        A::near(3.0, $result['deNetRentalCashflowPct']);
        A::same(false, $result['includeRentalCashflow']);
        A::same(null, $result['inflationSlug']);
        A::same(null, $result['customInflationPct']);
        A::same(false, $result['includeTer']);
        A::same(false, $result['taxSettings']['enabled']);
    },
    'Unbekanntes Asset wird abgelehnt' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        A::throws(static fn () => $validator->series([
            'assets' => 'free-url', 'start' => '2024-01-01', 'end' => '2024-12-31', 'amount' => 10000,
        ], ['sp500']), ValidationException::class);
    },
    'Zukunft, Datumsreihenfolge und Betrag werden abgelehnt' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        foreach ([
            ['start' => '2024-01-01', 'end' => '2099-01-01', 'amount' => 10000],
            ['start' => '2024-12-31', 'end' => '2024-01-01', 'amount' => 10000],
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'amount' => 10],
        ] as $input) {
            A::throws(static fn () => $validator->series(['assets' => 'sp500'] + $input, ['sp500']), ValidationException::class);
        }
    },
    'Der aktuelle Kalendertag ist als Enddatum erlaubt' => static function () use ($config): void {
        $today = new DateTimeImmutable('today');
        $result = (new RequestValidator($config))->series([
            'assets' => 'sp500',
            'start' => $today->modify('-1 day')->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'amount' => 10000,
        ], ['sp500']);
        A::same($today->format('Y-m-d'), $result['end']->format('Y-m-d'));
    },
    'Netto-Mietcashflow wird begrenzt und normalisiert' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        A::near(2.5, $validator->netRentalCashflowPct('2.5'));
        A::near(3.5, $validator->deNetRentalCashflowPct('3.5'));
        A::throws(static fn () => $validator->netRentalCashflowPct('15.1'), ValidationException::class);
        A::throws(static fn () => $validator->netRentalCashflowPct('-0.1'), ValidationException::class);
    },
    'Inflationsauswahl und eigene Rate werden validiert' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        $historical = $validator->series([
            'assets' => 'sp500',
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'inflation' => 'inflation_de',
        ], ['sp500']);
        A::same('inflation_de', $historical['inflationSlug']);
        A::same(null, $historical['customInflationPct']);

        $custom = $validator->series([
            'assets' => 'sp500',
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'inflation' => 'custom',
            'customInflationPct' => '2.5',
        ], ['sp500']);
        A::same(null, $custom['inflationSlug']);
        A::near(2.5, $custom['customInflationPct']);
        A::throws(static fn () => $validator->series([
            'assets' => 'sp500',
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'inflation' => 'custom',
            'customInflationPct' => '31',
        ], ['sp500']), ValidationException::class);
    },
    'Mietcashflow muss für Einzelverläufe ausdrücklich aktiviert werden' => static function () use ($config): void {
        $result = (new RequestValidator($config))->series([
            'assets' => 'us_real_estate',
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'includeRentalCashflow' => '1',
        ], ['us_real_estate']);
        A::same(true, $result['includeRentalCashflow']);
    },
    'TER und Steuerparameter werden begrenzt und normalisiert' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        $result = $validator->series([
            'assets' => 'sp500',
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'includeTer' => '1',
            'terPct' => '0.07',
            'includeTaxes' => '1',
            'personalIncomeTaxPct' => '42',
            'capitalAllowance' => '1000',
            'churchTaxPct' => '9',
        ], ['sp500']);
        A::same(true, $result['includeTer']);
        A::near(0.07, $result['terPct']);
        A::same(true, $result['taxSettings']['enabled']);
        A::near(42, $result['taxSettings']['personalIncomeTaxPct']);
        A::near(1000, $result['taxSettings']['capitalAllowance']);
        A::near(9, $result['taxSettings']['churchTaxPct']);
        A::throws(static fn () => $validator->series([
            'assets' => 'sp500', 'start' => '2024-01-01', 'end' => '2024-12-31', 'terPct' => '5.1',
        ], ['sp500']), ValidationException::class);
        A::throws(static fn () => $validator->series([
            'assets' => 'sp500', 'start' => '2024-01-01', 'end' => '2024-12-31', 'churchTaxPct' => '7',
        ], ['sp500']), ValidationException::class);
    },
];
