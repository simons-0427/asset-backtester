<?php

declare(strict_types=1);

use Smstark\Backtester\Providers\AlphaVantageProvider;
use Smstark\Backtester\Providers\BitcoinProvider;
use Smstark\Backtester\Providers\BlsInflationProvider;
use Smstark\Backtester\Providers\CsvImportProvider;
use Smstark\Backtester\Providers\EcbCompoundedIndexProvider;
use Smstark\Backtester\Providers\EcbExchangeRateProvider;
use Smstark\Backtester\Providers\EurostatHousePriceProvider;
use Smstark\Backtester\Providers\EurostatInflationProvider;
use Smstark\Backtester\Providers\FredProvider;
use Smstark\Backtester\Providers\FredCsvProvider;
use Smstark\Backtester\Providers\GoldApiProvider;
use Smstark\Backtester\Providers\HttpClient;
use Smstark\Backtester\Providers\MultplDividendYieldProvider;
use Smstark\Backtester\Providers\MsciWorldProvider;
use Smstark\Backtester\Providers\NyFedSofrIndexProvider;
use Smstark\Backtester\Providers\UsdToEurConverter;
use Smstark\Backtester\Providers\UsRealEstateProvider;
use Smstark\Backtester\Providers\YahooFundProvider;
use Smstark\Backtester\Providers\YahooIndexProvider;
use Smstark\Backtester\Tests\Assertions as A;

use function Smstark\Backtester\Tests\assetFixture;

return [
    'Multpl-Monatstabelle normalisiert Monate und erkennt Schätzungen' => static function (): void {
        $html = (string) file_get_contents(__DIR__ . '/fixtures/multpl-dividend-yield.html');
        $rows = MultplDividendYieldProvider::parseHtml($html);
        A::same(3, count($rows));
        A::same('2026-06-01', $rows[0]->observationMonth()->format('Y-m-d'));
        A::same('1.09', $rows[0]->annualYieldPct);
        A::same(false, $rows[0]->isEstimate);
        A::same('2026-08-01', $rows[2]->observationMonth()->format('Y-m-d'));
        A::same('2026-08-14', $rows[2]->sourceObservationDate->format('Y-m-d'));
        A::same(true, $rows[2]->isEstimate);
        A::same('multpl:2026-08-14:estimate', $rows[2]->sourceRevision());
    },
    'GoldAPI-XAU/USD-Antwort wird als USD-Beobachtung normalisiert' => static function (): void {
        $asset = assetFixture(
            slug: 'gold',
            providerType: 'goldapi',
            providerSymbol: 'XAU',
            providerDataset: 'USD',
            rawCurrency: 'USD',
            valueType: 'spot_reference',
        );
        $row = GoldApiProvider::parseResponse(
            (string) file_get_contents(__DIR__ . '/fixtures/goldapi.json'),
            $asset,
            new DateTimeImmutable('2026-08-05', new DateTimeZone('UTC')),
        );

        A::same('2026-08-05', $row?->date->format('Y-m-d'));
        A::same('4120.5', $row?->value);
        A::same('USD', $row?->currency);
        A::same('goldapi:XAU/USD:2026-08-05', $row?->sourceRevision);
        A::same(true, $row?->sourceTimestamp !== null);
    },
    'GoldAPI-Ablehnungen werden ohne Originalantwort sicher eingeordnet' => static function (): void {
        A::same(
            ['RATE_LIMITED', 'Das GoldAPI-Abruflimit wurde erreicht.'],
            GoldApiProvider::describeRejection('Monthly limit exceeded.'),
        );
        A::same(
            ['PROVIDER_KEY_INVALID', 'GoldAPI hat den API-Key als ungültig oder fehlend gemeldet.'],
            GoldApiProvider::describeRejection('Invalid API key.'),
        );
        A::same(
            ['PREMIUM_REQUIRED', 'GoldAPI erlaubt den historischen Abruf mit dem aktuellen Tarif nicht.'],
            GoldApiProvider::describeRejection('Historical data requires a premium plan.'),
        );
    },
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
        A::same(
            200,
            HttpClient::statusFromResponseHeaders([
                'HTTP/1.1 100 Continue',
                'HTTP/1.1 200 OK',
                'Content-Type: text/csv',
            ])
        );
    },
    'FRED-CSV-Parser übernimmt nur echte monatliche FHFA-Beobachtungen' => static function (): void {
        $asset = assetFixture(
            slug: 'us_real_estate',
            providerType: 'fred_csv',
            providerSymbol: 'HPIPONM226S',
            providerDataset: 'FHFA HPI',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
            nativeFrequency: 'monthly',
            calendarType: 'native_periods',
        );
        $rows = FredCsvProvider::parseCsv(
            "observation_date,HPIPONM226S\n2026-04-01,441.30\n2026-05-01,442.42\n2026-06-01,.\n",
            $asset,
            new DateTimeImmutable('2026-04-01'),
            new DateTimeImmutable('2026-06-01'),
        );
        A::same(2, count($rows));
        A::same('2026-05-01', $rows[1]->date->format('Y-m-d'));
        A::same('442.42', $rows[1]->eurValue);
        A::same('PTS', $rows[1]->currency);
    },
    'FRED-API übernimmt die freigegebene monatliche FHFA-Reihe' => static function (): void {
        $asset = assetFixture(
            slug: 'us_real_estate',
            providerType: 'fred',
            providerSymbol: 'HPIPONM226S',
            providerDataset: 'fred/series/observations: HPIPONM226S',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
            nativeFrequency: 'monthly',
            calendarType: 'native_periods',
        );
        $rows = FredProvider::parseJson(
            json_encode([
                'observations' => [
                    ['date' => '2026-04-01', 'value' => '441.30', 'realtime_start' => '2026-08-18'],
                    ['date' => '2026-05-01', 'value' => '442.42', 'realtime_start' => '2026-08-18'],
                    ['date' => '2026-06-01', 'value' => '.'],
                ],
            ], JSON_THROW_ON_ERROR),
            $asset,
            new DateTimeImmutable('2026-04-01'),
            new DateTimeImmutable('2026-06-01'),
        );

        A::same(true, FredProvider::supportsSeries('HPIPONM226S'));
        A::same(false, FredProvider::supportsSeries('UNKNOWN'));
        A::same(true, (new UsRealEstateProvider([]))->supports($asset));
        A::same(2, count($rows));
        A::same('2026-05-01', $rows[1]->date->format('Y-m-d'));
        A::same('442.42', $rows[1]->value);
        A::same('PTS', $rows[1]->currency);
        $normalized = UsRealEstateProvider::asIndexPoints($rows);
        A::same('442.42', $normalized[1]->eurValue);
        A::same('1', $normalized[1]->fxRateToEur);
        A::same('2026-05-01', $normalized[1]->fxObservationDate?->format('Y-m-d'));
    },
    'MSCI-World-Parser akzeptiert ausschließlich den exakten USD-Preisindex' => static function (): void {
        $asset = assetFixture(
            slug: 'msci_world',
            providerType: 'yahoo_chart_scraper',
            providerSymbol: '^990100-USD-STRD',
            providerDataset: 'MSCI World Price Index USD',
            rawCurrency: 'USD',
            valueType: 'index_level',
        );
        $json = json_encode([
            'chart' => ['result' => [[
                'meta' => ['symbol' => '^990100-USD-STRD', 'exchangeTimezoneName' => 'America/New_York'],
                'timestamp' => [1786680000, 1786852800],
                'indicators' => ['quote' => [['close' => [5029.5, null]]]],
            ]], 'error' => null],
        ], JSON_THROW_ON_ERROR);
        $rows = MsciWorldProvider::parseJson(
            $json,
            $asset,
            new DateTimeImmutable('2026-08-14'),
            new DateTimeImmutable('2026-08-16'),
        );
        A::same(1, count($rows));
        A::same('2026-08-14', $rows[0]->date->format('Y-m-d'));
        A::same('5029.5', $rows[0]->value);
    },
    'Yahoo-Indexparser übernimmt nur die freigegebenen europäischen Kursindizes' => static function (): void {
        $asset = assetFixture(
            slug: 'dax',
            providerType: 'yahoo_chart_scraper',
            providerSymbol: '^GDAXIP',
            providerDataset: 'DAX Price Index',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
        );
        $json = json_encode([
            'chart' => ['result' => [[
                'meta' => ['symbol' => '^GDAXIP', 'exchangeTimezoneName' => 'Europe/Berlin'],
                'timestamp' => [1786680000, 1786852800],
                'indicators' => ['quote' => [['close' => [9531.25, null]]]],
            ]], 'error' => null],
        ], JSON_THROW_ON_ERROR);
        $rows = YahooIndexProvider::parseJson(
            $json,
            $asset,
            new DateTimeImmutable('2026-08-14'),
            new DateTimeImmutable('2026-08-16'),
        );

        A::same(true, (new YahooIndexProvider([]))->supports($asset));
        A::same(1, count($rows));
        A::same('2026-08-14', $rows[0]->date->format('Y-m-d'));
        A::same('9531.25', $rows[0]->value);
        A::same('9531.25', $rows[0]->eurValue);
        A::same('1', $rows[0]->fxRateToEur);
        A::same('PTS', $rows[0]->currency);
    },
    'Eurostat-Parser übernimmt nur deutsche Gesamtmarkt-Quartalswerte' => static function (): void {
        $asset = assetFixture(
            slug: 'de_real_estate',
            providerType: 'eurostat_statistics_api',
            providerSymbol: 'prc_hpi_q:DE:TOTAL:I15_Q',
            providerDataset: 'Eurostat HPI',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
            nativeFrequency: 'quarterly',
            calendarType: 'native_periods',
        );
        $json = json_encode([
            'version' => '2.0',
            'class' => 'dataset',
            'source' => 'ESTAT',
            'updated' => '2026-07-02T11:00:00+0200',
            'value' => ['0' => 153.0, '1' => 153.4, '2' => 154.1],
            'status' => ['2' => 'p'],
            'id' => ['freq', 'purchase', 'unit', 'geo', 'time'],
            'size' => [1, 1, 1, 1, 3],
            'dimension' => [
                'freq' => ['category' => ['index' => ['Q' => 0]]],
                'purchase' => ['category' => ['index' => ['TOTAL' => 0]]],
                'unit' => ['category' => ['index' => ['I15_Q' => 0]]],
                'geo' => ['category' => ['index' => ['DE' => 0]]],
                'time' => ['category' => ['index' => [
                    '2025-Q4' => 0,
                    '2026-Q1' => 1,
                    '2026-Q2' => 2,
                ]]],
            ],
        ], JSON_THROW_ON_ERROR);
        $rows = EurostatHousePriceProvider::parseJson(
            $json,
            $asset,
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-07-01'),
        );

        A::same(true, (new EurostatHousePriceProvider([]))->supports($asset));
        A::same(2, count($rows));
        A::same('2026-03-31', $rows[0]->date->format('Y-m-d'));
        A::same('2026-06-30', $rows[1]->date->format('Y-m-d'));
        A::same('154.1', $rows[1]->value);
        A::same('154.1', $rows[1]->eurValue);
        A::same('provisional', $rows[1]->qualityStatus);
        A::same('eurostat:prc_hpi_q:DE:2026-Q2', $rows[1]->sourceRevision);
    },
    'Yahoo-Fondsparser verbindet neue adjustierte Werte ohne Sprung mit der Importhistorie' => static function (): void {
        $asset = assetFixture(
            slug: 'global_ig_bonds_unhedged',
            providerType: 'yahoo_fund_scraper',
            providerSymbol: 'AGGG.L',
            providerDataset: 'AGGG adjusted close in EUR view',
            rawCurrency: 'PTS',
            valueType: 'adjusted_close',
            displayCurrency: 'PTS',
        );
        $london = new DateTimeZone('Europe/London');
        $json = json_encode([
            'chart' => ['result' => [[
                'meta' => [
                    'symbol' => 'AGGG.L',
                    'currency' => 'USD',
                    'exchangeTimezoneName' => 'Europe/London',
                ],
                'timestamp' => [
                    (new DateTimeImmutable('2026-08-20 12:00:00', $london))->getTimestamp(),
                    (new DateTimeImmutable('2026-08-21 12:00:00', $london))->getTimestamp(),
                ],
                'indicators' => [
                    'adjclose' => [['adjclose' => [4.31750011, 4.35]]],
                    'quote' => [['close' => [4.31750011, 4.35]]],
                ],
            ]], 'error' => null],
        ], JSON_THROW_ON_ERROR);
        $rows = YahooFundProvider::parseJson(
            $json,
            $asset,
            new DateTimeImmutable('2026-08-20'),
            new DateTimeImmutable('2026-08-21'),
            ['2026-08-20' => '1.1681', '2026-08-21' => '1.17'],
        );

        A::same(true, (new YahooFundProvider([]))->supports($asset));
        A::same(2, count($rows));
        A::same('100.44407723', $rows[0]->value);
        A::near(
            100.44407723 * (4.35 / 1.17) / (4.31750011 / 1.1681),
            (float) $rows[1]->value,
            0.0000001,
        );
        A::same('PTS', $rows[1]->currency);
        A::same('2026-08-21', $rows[1]->fxObservationDate?->format('Y-m-d'));
    },
    'EZB-Parser verbindet den offiziellen EURSTR-Index mit dem importierten Geldmarkt' => static function (): void {
        $asset = assetFixture(
            slug: 'euro_money_market',
            providerType: 'ecb_compounded_index',
            providerSymbol: 'EST.B.EU000A2QQF08.CI',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
        );
        $csv = implode("\n", [
            'KEY,FREQ,BENCHMARK_ITEM,DATA_TYPE_EST,TIME_PERIOD,OBS_VALUE,OBS_STATUS',
            'EST.B.EU000A2QQF08.CI,B,EU000A2QQF08,CI,2026-08-19,109.59984933,A',
            'EST.B.EU000A2QQF08.CI,B,EU000A2QQF08,CI,2026-08-20,109.6065,P',
        ]);
        $rows = EcbCompoundedIndexProvider::parseCsv(
            $csv,
            $asset,
            new DateTimeImmutable('2026-08-19'),
            new DateTimeImmutable('2026-08-20'),
        );

        A::same(true, (new EcbCompoundedIndexProvider([]))->supports($asset));
        A::same(2, count($rows));
        A::same('108.152193', $rows[0]->value);
        A::same('provisional', $rows[1]->qualityStatus);
    },
    'NY-Fed-Parser verbindet SOFR-Index und datierten EZB-Kurs mit der Importhistorie' => static function (): void {
        $asset = assetFixture(
            slug: 'us_money_market',
            providerType: 'nyfed_sofr_index_eur',
            providerSymbol: 'NYFED:SOFRAI',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
        );
        $csv = implode("\n", [
            'Effective Date,Rate Type,SOFR Index,Revision Indicator (Y/N)',
            '08/20/2026,SOFRAI,1.25553243,',
            '08/19/2026,SOFRAI,1.25540619,N',
        ]);
        $rows = NyFedSofrIndexProvider::parseCsv(
            $csv,
            $asset,
            new DateTimeImmutable('2026-08-19'),
            new DateTimeImmutable('2026-08-20'),
            ['2026-08-19' => '1.1605', '2026-08-20' => '1.1681'],
        );

        A::same(true, (new NyFedSofrIndexProvider([]))->supports($asset));
        A::same(2, count($rows));
        A::same('120.01127877', $rows[0]->value);
        A::near(
            120.01127877 * (1.25553243 / 1.1681) / (1.25540619 / 1.1605),
            (float) $rows[1]->value,
            0.0000001,
        );
    },
    'Eurostat-HICP-Parser speichert Monatswerte am echten Monatsende' => static function (): void {
        $asset = assetFixture(
            slug: 'inflation_de',
            providerType: 'eurostat_hicp_api',
            providerSymbol: 'prc_hicp_minr:DE:TOTAL:I25',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
            nativeFrequency: 'monthly',
            calendarType: 'native_periods',
        );
        $json = json_encode([
            'version' => '2.0',
            'class' => 'dataset',
            'source' => 'ESTAT',
            'updated' => '2026-08-19T11:00:00+0200',
            'value' => ['0' => 102.32, '1' => 103.20],
            'status' => ['1' => 'p'],
            'id' => ['freq', 'unit', 'coicop18', 'geo', 'time'],
            'size' => [1, 1, 1, 1, 2],
            'dimension' => [
                'freq' => ['category' => ['index' => ['M' => 0]]],
                'unit' => ['category' => ['index' => ['I25' => 0]]],
                'coicop18' => ['category' => ['index' => ['TOTAL' => 0]]],
                'geo' => ['category' => ['index' => ['DE' => 0]]],
                'time' => ['category' => ['index' => ['2026-06' => 0, '2026-07' => 1]]],
            ],
        ], JSON_THROW_ON_ERROR);
        $rows = EurostatInflationProvider::parseJson(
            $json,
            $asset,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-08-20'),
        );

        A::same(true, (new EurostatInflationProvider([]))->supports($asset));
        A::same(2, count($rows));
        A::same('2026-06-30', $rows[0]->date->format('Y-m-d'));
        A::same('2026-07-31', $rows[1]->date->format('Y-m-d'));
        A::same('provisional', $rows[1]->qualityStatus);
    },
    'BLS-Parser uebernimmt nur echte CPI-U-Monate und markiert den neuesten Wert' => static function (): void {
        $asset = assetFixture(
            slug: 'inflation_us',
            providerType: 'bls_public_api',
            providerSymbol: 'CUUR0000SA0',
            rawCurrency: 'PTS',
            valueType: 'index_level',
            displayCurrency: 'PTS',
            nativeFrequency: 'monthly',
            calendarType: 'native_periods',
        );
        $json = json_encode([
            'status' => 'REQUEST_SUCCEEDED',
            'Results' => ['series' => [[
                'seriesID' => 'CUUR0000SA0',
                'data' => [
                    ['year' => '2026', 'period' => 'M13', 'value' => '333.0'],
                    ['year' => '2026', 'period' => 'M07', 'value' => '333.918', 'latest' => 'true'],
                    ['year' => '2026', 'period' => 'M06', 'value' => '333.952'],
                ],
            ]]],
        ], JSON_THROW_ON_ERROR);
        $rows = BlsInflationProvider::parseJson(
            $json,
            $asset,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-08-20'),
        );

        A::same(true, (new BlsInflationProvider([]))->supports($asset));
        A::same(2, count($rows));
        A::same('2026-06-30', $rows[0]->date->format('Y-m-d'));
        A::same('2026-07-31', $rows[1]->date->format('Y-m-d'));
        A::same('333.918', $rows[1]->value);
        A::same('provisional', $rows[1]->qualityStatus);
    },
];
