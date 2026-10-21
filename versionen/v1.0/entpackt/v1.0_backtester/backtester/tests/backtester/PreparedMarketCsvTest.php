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
];
