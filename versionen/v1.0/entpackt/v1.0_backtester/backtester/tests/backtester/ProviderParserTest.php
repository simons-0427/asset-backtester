<?php

declare(strict_types=1);

use Smstark\Backtester\Providers\AlphaVantageProvider;
use Smstark\Backtester\Providers\BitcoinProvider;
use Smstark\Backtester\Providers\CsvImportProvider;
use Smstark\Backtester\Providers\EcbExchangeRateProvider;
use Smstark\Backtester\Providers\FredProvider;
use Smstark\Backtester\Providers\HttpClient;
use Smstark\Backtester\Providers\UsdToEurConverter;
use Smstark\Backtester\Tests\Assertions as A;

use function Smstark\Backtester\Tests\assetFixture;

return [
    'Alpha-Vantage-Free-CSV nutzt unbereinigten Schluss und sortiert aufsteigend' => static function (): void {
        $csv = file_get_contents(__DIR__ . '/fixtures/alpha-vantage.csv');
        $rows = AlphaVantageProvider::parseCsv(
            $csv,
            assetFixture(slug: 'gold', providerSymbol: 'GLD', providerDataset: 'TIME_SERIES_DAILY', rawCurrency: 'USD', valueType: 'close'),
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-31'),
        );
        A::same(3, count($rows));
        A::same('2024-01-02', $rows[0]->date->format('Y-m-d'));
        A::same('103.5', $rows[2]->value);
    },
    'FRED-SP500 und EZB-Kurs ergeben nachvollziehbare EUR-Werte' => static function (): void {
        $asset = assetFixture(
            providerType: 'fred',
            providerSymbol: 'SP500',
            providerDataset: 'fred/series/observations',
            rawCurrency: 'USD',
            valueType: 'index_level',
        );
        $from = new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-08-04', new DateTimeZone('UTC'));
        $raw = FredProvider::parseJson(
            (string) file_get_contents(__DIR__ . '/fixtures/fred-sp500.json'),
            $asset,
            $from,
            $to,
        );
        $rates = EcbExchangeRateProvider::parseCsv(
            (string) file_get_contents(__DIR__ . '/fixtures/ecb-usd-eur.csv'),
            new DateTimeImmutable('2026-07-25', new DateTimeZone('UTC')),
            $to,
        );
        $rows = UsdToEurConverter::convertWithRates($raw, $rates);

        A::same(2, count($rows));
        A::same('USD', $rows[0]->currency);
        A::same('6375.25', $rows[0]->value);
        A::same('5495.9051724138', $rows[0]->eurValue);
        A::same('2026-08-03', $rows[1]->fxObservationDate?->format('Y-m-d'));
        $validated = (new \Smstark\Backtester\Services\ValidationService())->validate([
            $rows[0],
            new \Smstark\Backtester\Domain\HistoricalObservation(
                new DateTimeImmutable('2026-08-04', new DateTimeZone('UTC')),
                '10',
                'USD',
                null,
                'valid',
                'test',
                null,
                '8.6',
                '0.86',
                new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC')),
            ),
        ], $asset);
        A::same('flagged', $validated[1]->qualityStatus);
        A::same('8.6', $validated[1]->eurValue);
        A::same('0.86', $validated[1]->fxRateToEur);
        A::same('2026-08-03', $validated[1]->fxObservationDate?->format('Y-m-d'));
    },
    'FRED- und Alpha-Vantage-Livebereiche schützen die importierte Historie' => static function (): void {
        [$from, $to] = FredProvider::recentRange(
            10,
            '2026-08-03',
            new DateTimeImmutable('2026-08-05', new DateTimeZone('UTC')),
        );
        A::same('2026-08-03', $from->format('Y-m-d'));
        A::same('2026-08-04', $to->format('Y-m-d'));
        [$alphaFrom, $alphaTo] = AlphaVantageProvider::recentRange(
            10,
            '2026-08-03',
            new DateTimeImmutable('2026-08-05', new DateTimeZone('UTC')),
        );
        A::same('2026-08-03', $alphaFrom->format('Y-m-d'));
        A::same('2026-08-04', $alphaTo->format('Y-m-d'));
    },
    'Alpha-Vantage-Grenztag bleibt unabhängig von der Serverzeitzone enthalten' => static function (): void {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $rows = AlphaVantageProvider::parseCsv(
                "timestamp,open,high,low,close,volume\n2026-08-03,1,2,1,1.5,100\n",
                assetFixture(
                    slug: 'gold',
                    providerSymbol: 'GLD',
                    providerDataset: 'TIME_SERIES_DAILY',
                    rawCurrency: 'USD',
                    valueType: 'close',
                ),
                new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC')),
            );
            A::same(1, count($rows));
            A::same('2026-08-03', $rows[0]->date->format('Y-m-d'));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    },
    'Alpha-Vantage-Ablehnungen werden ohne Originalantwort sicher eingeordnet' => static function (): void {
        A::same(
            ['PREMIUM_REQUIRED', 'Alpha Vantage bezeichnet den angefragten Endpunkt als kostenpflichtig.'],
            AlphaVantageProvider::describeRejection('Thank you. This is a premium endpoint.'),
        );
        A::same(
            ['RATE_LIMITED', 'Das kostenlose Alpha-Vantage-Tageslimit wurde erreicht.'],
            AlphaVantageProvider::describeRejection('Limited to 25 requests per day.'),
        );
        A::same(
            ['PROVIDER_KEY_INVALID', 'Alpha Vantage hat den API-Key als ungültig oder fehlend gemeldet.'],
            AlphaVantageProvider::describeRejection('The parameter apikey is invalid or missing.'),
        );
        A::same(
            ['PROVIDER_INVALID_REQUEST', 'Alpha Vantage hat die Anfrageparameter abgelehnt.'],
            AlphaVantageProvider::describeRejection('Invalid API call. Please retry.'),
        );
    },
    'CoinGecko-Parser nimmt den letzten gelieferten Punkt je UTC-Tag' => static function (): void {
        $json = file_get_contents(__DIR__ . '/fixtures/coingecko.json');
        $rows = BitcoinProvider::parseJson($json, assetFixture(slug: 'bitcoin', providerType: 'coingecko'), new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-02'));
        A::same(2, count($rows));
        A::same('42000.25', $rows[0]->value);
        A::same('43000.5', $rows[1]->value);
    },
    'CoinGecko-Liveabruf schützt die importierte Historie und nutzt Stundenwerte' => static function (): void {
        [$from, $to] = BitcoinProvider::recentRange(
            10,
            '2026-08-03',
            new DateTimeImmutable('2026-08-04', new DateTimeZone('UTC')),
        );
        A::same('2026-08-03', $from->format('Y-m-d'));
        A::same('2026-08-03', $to->format('Y-m-d'));
        A::same('hourly', BitcoinProvider::intervalForRange($from, $to));
        A::same(
            'daily',
            BitcoinProvider::intervalForRange(
                new DateTimeImmutable('2026-01-01'),
                new DateTimeImmutable('2026-04-11'),
            )
        );
    },
    'CSV-Import bleibt deterministisch und filtert den Bereich' => static function (): void {
        $csv = file_get_contents(__DIR__ . '/fixtures/import.csv');
        $rows = CsvImportProvider::parseCsv($csv, assetFixture(), new DateTimeImmutable('2024-01-02'), new DateTimeImmutable('2024-01-03'));
        A::same(2, count($rows));
        A::same('revised', $rows[1]->qualityStatus);
    },
    'CSV-Grenztage bleiben unabhängig von der Serverzeitzone enthalten' => static function (): void {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $rows = CsvImportProvider::parseCsv(
                "date,value,currency\n2016-08-02,500,EUR\n2026-08-02,50000,EUR\n",
                assetFixture(slug: 'bitcoin', providerType: 'csv'),
                new DateTimeImmutable('2016-08-02', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
            );
            A::same(2, count($rows));
            A::same('2016-08-02', $rows[0]->date->format('Y-m-d'));
            A::same('2026-08-02', $rows[1]->date->format('Y-m-d'));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    },
    'HTTP-Fehler werden ohne Schlüssel oder URL eindeutig protokolliert' => static function (): void {
        A::same(
            ['HTTP_UNAUTHORIZED', 'Der Datenanbieter hat den API-Key abgelehnt (HTTP 401).'],
            HttpClient::describeFailure(401, 0)
        );
        A::same(
            ['RATE_LIMITED', 'Das Abruflimit des Datenanbieters wurde erreicht (HTTP 429).'],
            HttpClient::describeFailure(429, 0)
        );
        A::same(
            ['HTTP_TRANSPORT_ERROR', 'Die Verbindung zum Datenanbieter ist fehlgeschlagen (cURL 28).'],
            HttpClient::describeFailure(0, 28)
        );
    },
];
