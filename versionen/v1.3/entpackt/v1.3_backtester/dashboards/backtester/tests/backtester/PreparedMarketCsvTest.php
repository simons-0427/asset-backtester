<?php

declare(strict_types=1);

use Smstark\Backtester\Providers\CsvImportProvider;
use Smstark\Backtester\Tests\Assertions as A;
use Smstark\Backtester\Tests\SkippedTest;

use function Smstark\Backtester\Tests\assetFixture;

return [
    'Vorbereitete S&P-500- und Gold-Dateien sind importierbare EUR-Börsentagsreihen' => static function (): void {
        $directory = dirname(__DIR__, 2) . '/var/import';
        $from = new DateTimeImmutable('2016-08-02', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC'));

        foreach (['sp500' => 'fred-sp500:', 'gold' => 'spdr-gld-official:'] as $slug => $revisionPrefix) {
            $file = $directory . '/' . $slug . '.csv';
            if (!is_file($file)) {
                throw new SkippedTest('Vorbereitete Markt-CSV fehlt: ' . $slug . '.csv');
            }
            $rows = CsvImportProvider::parseCsv(
                (string) file_get_contents($file),
                assetFixture(slug: $slug, providerType: 'csv', rawCurrency: 'USD'),
                $from,
                $to,
            );
            A::same(2513, count($rows));
            A::same('2016-08-02', $rows[0]->date->format('Y-m-d'));
            A::same('2026-07-31', $rows[array_key_last($rows)]->date->format('Y-m-d'));

            $seenDates = [];
            foreach ($rows as $row) {
                $date = $row->date->format('Y-m-d');
                A::same('USD', $row->currency);
                A::true((float) $row->value > 0);
                A::true((float) $row->eurValue > 0);
                A::true((float) $row->fxRateToEur > 0);
                A::true($row->fxObservationDate !== null);
                A::true(str_starts_with((string) $row->sourceRevision, $revisionPrefix));
                A::true(!isset($seenDates[$date]), 'Doppelter Marktbeobachtungstag: ' . $date);
                A::true(!in_array($row->date->format('N'), ['6', '7'], true), 'Künstlicher Wochenendwert: ' . $date);
                $seenDates[$date] = true;
            }
        }
    },
    'Vorbereitete MSCI-World-Datei verbindet Monats- und Tageswerte in EUR' => static function (): void {
        $file = dirname(__DIR__, 2) . '/var/import/msci_world.csv';
        if (!is_file($file)) {
            throw new SkippedTest('Vorbereitete Markt-CSV fehlt: msci_world.csv');
        }
        $rows = CsvImportProvider::parseCsv(
            (string) file_get_contents($file),
            assetFixture(slug: 'msci_world', providerType: 'manual_csv', rawCurrency: 'USD'),
            new DateTimeImmutable('2016-07-31', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-14', new DateTimeZone('UTC')),
        );

        A::same(1115, count($rows));
        A::same('2016-07-31', $rows[0]->date->format('Y-m-d'));
        A::same('2026-08-14', $rows[array_key_last($rows)]->date->format('Y-m-d'));
        A::near(1716, (float) $rows[0]->value, 0.000001);
        A::near(1544.1374966256, (float) $rows[0]->eurValue, 0.000001);

        $seenDates = [];
        $monthlyRows = 0;
        $manualMonthlyRows = 0;
        $dailyRows = 0;
        foreach ($rows as $row) {
            $date = $row->date->format('Y-m-d');
            A::same('USD', $row->currency);
            A::true((float) $row->value > 0);
            A::true((float) $row->eurValue > 0);
            A::true((float) $row->fxRateToEur > 0);
            A::true($row->fxObservationDate !== null && $row->fxObservationDate <= $row->date);
            A::true(!isset($seenDates[$date]), 'Doppelter MSCI-World-Beobachtungstag: ' . $date);
            $isManualMonthEnd = $date === '2016-07-31'
                && str_starts_with((string) $row->sourceRevision, 'msci-world-price-manual-monthly:');
            A::true(
                $isManualMonthEnd || !in_array($row->date->format('N'), ['6', '7'], true),
                'Unerwarteter Wochenendwert: ' . $date,
            );
            $monthlyRows += str_starts_with((string) $row->sourceRevision, 'msci-world-price-monthly:') ? 1 : 0;
            $manualMonthlyRows += str_starts_with((string) $row->sourceRevision, 'msci-world-price-manual-monthly:') ? 1 : 0;
            $dailyRows += str_starts_with((string) $row->sourceRevision, 'msci-world-price-daily:') ? 1 : 0;
            $seenDates[$date] = true;
        }
        A::same(72, $monthlyRows);
        A::same(1, $manualMonthlyRows);
        A::same(1042, $dailyRows);
    },
    'Vorbereitete europäische Preisreihen bleiben echte Beobachtungen' => static function (): void {
        $directory = dirname(__DIR__, 2) . '/var/import';
        $cases = [
            'dax' => [2696, '2016-01-04', '2026-08-20', 'yahoo:^GDAXIP'],
            'eurostoxx50' => [2672, '2016-01-04', '2026-08-20', 'yahoo:^STOXX50E'],
        ];
        foreach ($cases as $slug => [$count, $first, $last, $revision]) {
            $rows = CsvImportProvider::parseCsv(
                (string) file_get_contents($directory . '/' . $slug . '.csv'),
                assetFixture(
                    slug: $slug,
                    providerType: 'manual_csv',
                    rawCurrency: 'PTS',
                    displayCurrency: 'PTS',
                ),
                new DateTimeImmutable($first, new DateTimeZone('UTC')),
                new DateTimeImmutable($last, new DateTimeZone('UTC')),
            );
            A::same($count, count($rows));
            A::same($first, $rows[0]->date->format('Y-m-d'));
            A::same($last, $rows[array_key_last($rows)]->date->format('Y-m-d'));
            foreach ($rows as $row) {
                A::same('PTS', $row->currency);
                A::same($revision, $row->sourceRevision);
                A::true(!in_array($row->date->format('N'), ['6', '7'], true));
            }
        }
    },
    'Vorbereitete deutsche Immobilienreihe bleibt quartalsweise' => static function (): void {
        $file = dirname(__DIR__, 2) . '/var/import/de_real_estate.csv';
        $rows = CsvImportProvider::parseCsv(
            (string) file_get_contents($file),
            assetFixture(
                slug: 'de_real_estate',
                providerType: 'manual_csv',
                rawCurrency: 'PTS',
                displayCurrency: 'PTS',
                nativeFrequency: 'quarterly',
                calendarType: 'native_periods',
            ),
            new DateTimeImmutable('2016-03-31', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-03-31', new DateTimeZone('UTC')),
        );
        A::same(41, count($rows));
        A::same('2016-03-31', $rows[0]->date->format('Y-m-d'));
        A::same('2026-03-31', $rows[array_key_last($rows)]->date->format('Y-m-d'));
        A::same('provisional', $rows[array_key_last($rows)]->qualityStatus);
        foreach ($rows as $row) {
            A::same('PTS', $row->currency);
            A::true(in_array($row->date->format('m-d'), ['03-31', '06-30', '09-30', '12-31'], true));
        }
    },
    'Neue Anleihen-, Rohstoff- und Geldmarktreihen sind importierbar' => static function (): void {
        $directory = dirname(__DIR__, 2) . '/var/import';
        $cases = [
            'global_ig_bonds' => [2705, '2016-01-04', '2026-08-20', 'EUR'],
            'global_ig_bonds_unhedged' => [2704, '2016-01-04', '2026-08-20', 'PTS'],
            'commodities' => [2686, '2016-01-04', '2026-08-20', 'PTS'],
            'euro_money_market' => [2721, '2016-01-04', '2026-08-19', 'PTS'],
            'us_money_market' => [2658, '2016-01-04', '2026-08-19', 'PTS'],
        ];
        foreach ($cases as $slug => [$count, $first, $last, $currency]) {
            $rows = CsvImportProvider::parseCsv(
                (string) file_get_contents($directory . '/' . $slug . '.csv'),
                assetFixture(
                    slug: $slug,
                    providerType: 'manual_csv',
                    rawCurrency: $currency,
                    displayCurrency: $currency,
                ),
                new DateTimeImmutable($first, new DateTimeZone('UTC')),
                new DateTimeImmutable($last, new DateTimeZone('UTC')),
            );
            A::same($count, count($rows), 'Unerwartete Zeilenzahl für ' . $slug);
            A::same($first, $rows[0]->date->format('Y-m-d'));
            A::same($last, $rows[array_key_last($rows)]->date->format('Y-m-d'));
            foreach ($rows as $row) {
                A::same($currency, $row->currency);
                A::true((float) $row->value > 0);
            }
        }
    },
    'Inflationsdateien enthalten ausschließlich monatliche Preisindizes' => static function (): void {
        $directory = dirname(__DIR__, 2) . '/var/import';
        $cases = [
            'inflation_de' => [127, '2016-01-31', '2026-07-31'],
            'inflation_euro_area' => [127, '2016-01-31', '2026-07-31'],
            'inflation_us' => [126, '2016-01-31', '2026-07-31'],
        ];
        foreach ($cases as $slug => [$count, $first, $last]) {
            $rows = CsvImportProvider::parseCsv(
                (string) file_get_contents($directory . '/' . $slug . '.csv'),
                assetFixture(
                    slug: $slug,
                    providerType: 'manual_csv',
                    rawCurrency: 'PTS',
                    displayCurrency: 'PTS',
                    nativeFrequency: 'monthly',
                    calendarType: 'native_periods',
                ),
                new DateTimeImmutable($first, new DateTimeZone('UTC')),
                new DateTimeImmutable($last, new DateTimeZone('UTC')),
            );
            A::same($count, count($rows));
            A::same($first, $rows[0]->date->format('Y-m-d'));
            A::same($last, $rows[array_key_last($rows)]->date->format('Y-m-d'));
            foreach ($rows as $row) {
                A::true((float) $row->value > 0);
                A::true(in_array($row->date->format('d'), ['28', '29', '30', '31'], true));
            }
        }
    },
];
