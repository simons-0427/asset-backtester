<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

/**
 * Extends the imported ETF/ETC total-return proxies with recent Yahoo adjusted
 * closes. Every response contains the fixed database anchor so later dividend
 * adjustments and splits cannot introduce a discontinuity at the CSV boundary.
 */
final class YahooFundProvider implements HistoricalDataProviderInterface
{
    private const SERIES = [
        'global_ig_bonds' => [
            'symbol' => 'EUNA.DE',
            'source_currency' => 'EUR',
            'anchor_date' => '2026-08-20',
            'anchor_value' => 4.88990021,
        ],
        'global_ig_bonds_unhedged' => [
            'symbol' => 'AGGG.L',
            'source_currency' => 'USD',
            'anchor_date' => '2026-08-20',
            'anchor_value' => 100.44407723,
        ],
        'commodities' => [
            'symbol' => 'AIGC.L',
            'source_currency' => 'USD',
            'anchor_date' => '2026-08-20',
            'anchor_value' => 196.42179543,
        ],
    ];

    private readonly HttpClient $http;
    private readonly EcbExchangeRateProvider $exchangeRates;

    public function __construct(private readonly array $config = [], array $ecbConfig = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 2),
            (int) ($config['retry_base_ms'] ?? 750),
            (bool) ($config['prefer_php_stream'] ?? false),
        );
        $this->exchangeRates = new EcbExchangeRateProvider($ecbConfig);
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $series = self::assertAsset($asset);
        $utc = new DateTimeZone('UTC');
        $anchor = new DateTimeImmutable($series['anchor_date'], $utc);
        if ($to < $anchor) {
            throw new ProviderException(
                'Der Live-Abruf beginnt erst am Uebergang zur importierten Historie.',
                'LIVE_RANGE_BEFORE_ANCHOR',
            );
        }

        $template = (string) ($this->config['url_template']
            ?? 'https://query1.finance.yahoo.com/v8/finance/chart/%s');
        $url = sprintf($template, rawurlencode($asset->providerSymbol));
        $query = http_build_query([
            'period1' => $anchor->modify('-2 days')->getTimestamp(),
            'period2' => $to->modify('+2 days')->getTimestamp(),
            'interval' => '1d',
            'events' => 'history',
            'includeAdjustedClose' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);

        $rates = [];
        if ($series['source_currency'] === 'USD') {
            $rates = $this->exchangeRates->fetchUsdPerEur($anchor->modify('-10 days'), $to);
        }

        return self::parseJson(
            $this->http->get(rtrim($url, '?') . '?' . $query),
            $asset,
            $from,
            $to,
            $rates,
        );
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        $utc = new DateTimeZone('UTC');
        $to = new DateTimeImmutable('today', $utc);
        $from = $to->modify(sprintf('-%d days', max(10, min(28, $lookbackDays))));
        return $this->fetchRange($asset, $from, $to);
    }

    public function supports(AssetDefinition $asset): bool
    {
        return isset(self::SERIES[$asset->slug])
            && $asset->providerType === 'yahoo_fund_scraper';
    }

    /**
     * @param array<string, string> $usdPerEurByDate
     * @return list<HistoricalObservation>
     */
    public static function parseJson(
        string $json,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $usdPerEurByDate = [],
    ): array {
        $series = self::assertAsset($asset);
        $payload = json_decode($json, true);
        $result = $payload['chart']['result'][0] ?? null;
        if (!is_array($result) || !is_array($result['timestamp'] ?? null)) {
            throw new ProviderException('Unerwartetes Yahoo-Antwortformat fuer den Fondsproxy.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $adjusted = $result['indicators']['adjclose'][0]['adjclose'] ?? null;
        if (!is_array($adjusted)) {
            throw new ProviderException('In der Yahoo-Antwort fehlen adjustierte Schlusswerte.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $returnedSymbol = (string) ($result['meta']['symbol'] ?? '');
        if ($returnedSymbol !== $asset->providerSymbol) {
            throw new ProviderException('Der Fondsabruf lieferte ein anderes Symbol.', 'SYMBOL_MISMATCH');
        }
        $returnedCurrency = strtoupper((string) ($result['meta']['currency'] ?? ''));
        if ($returnedCurrency !== $series['source_currency']) {
            throw new ProviderException('Der Fondsabruf lieferte eine andere Handelswaehrung.', 'CURRENCY_MISMATCH');
        }

        try {
            $marketTimezone = new DateTimeZone((string) (
                $result['meta']['exchangeTimezoneName'] ?? $asset->timezoneName ?? 'UTC'
            ));
        } catch (\Throwable) {
            throw new ProviderException('Ungueltige Zeitzone in der Yahoo-Antwort.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $rawRows = [];
        foreach ($result['timestamp'] as $offset => $timestamp) {
            $rawValue = $adjusted[$offset] ?? null;
            if (!is_numeric($timestamp) || !is_numeric($rawValue) || (float) $rawValue <= 0) {
                continue;
            }
            $sourceTimestamp = new DateTimeImmutable('@' . (string) $timestamp);
            $date = $sourceTimestamp->setTimezone($marketTimezone)->setTime(0, 0);
            $rawRows[$date->format('Y-m-d')] = [
                'date' => $date,
                'timestamp' => $sourceTimestamp,
                'value' => (float) $rawValue,
            ];
        }

        $anchorDate = $series['anchor_date'];
        if (!isset($rawRows[$anchorDate])) {
            throw new ProviderException('Der Yahoo-Abruf enthaelt den festen Anschlusswert nicht.', 'ANCHOR_MISSING');
        }
        $rates = self::ratesAtObservationDates($rawRows, $usdPerEurByDate, $series['source_currency']);
        $anchorSourceEur = self::sourceEurValue(
            $rawRows[$anchorDate]['value'],
            $series['source_currency'],
            $rates[$anchorDate]['rate'] ?? null,
        );
        if (!is_finite($anchorSourceEur) || $anchorSourceEur <= 0) {
            throw new ProviderException('Der Yahoo-Anschlusswert kann nicht normiert werden.', 'ANCHOR_INVALID');
        }

        $fromDate = max($from->format('Y-m-d'), $anchorDate);
        $toDate = $to->format('Y-m-d');
        $today = (new DateTimeImmutable('today', $marketTimezone))->format('Y-m-d');
        $observations = [];
        foreach ($rawRows as $dateText => $row) {
            if ($dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $sourceEur = self::sourceEurValue(
                $row['value'],
                $series['source_currency'],
                $rates[$dateText]['rate'] ?? null,
            );
            $normalized = (float) $series['anchor_value'] * $sourceEur / $anchorSourceEur;
            $decimal = self::decimal($normalized, 10);
            $fxDate = $rates[$dateText]['date'] ?? $dateText;
            $fxRate = $rates[$dateText]['rate'] ?? '1';
            $currency = strtoupper($asset->rawCurrency);
            $revision = 'yahoo-adj:' . $returnedSymbol . ':' . $dateText;
            if ($series['source_currency'] === 'USD') {
                $revision .= ';ecb-usd-eur:' . $fxDate;
            }
            $observations[] = new HistoricalObservation(
                $row['date'],
                $decimal,
                $currency,
                $row['timestamp'],
                $dateText === $today ? 'provisional' : 'valid',
                substr($revision, 0, 100),
                hash('sha256', implode('|', [
                    $dateText,
                    self::decimal($row['value'], 10),
                    $decimal,
                    (string) $fxDate,
                    (string) $fxRate,
                    $returnedSymbol,
                ])),
                $decimal,
                '1',
                new DateTimeImmutable((string) $fxDate, new DateTimeZone('UTC')),
            );
        }
        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    /** @return array{symbol: string, source_currency: string, anchor_date: string, anchor_value: float} */
    private static function assertAsset(AssetDefinition $asset): array
    {
        $series = self::SERIES[$asset->slug] ?? null;
        if ($series === null || $asset->providerSymbol !== $series['symbol']) {
            throw new ProviderException('Fuer den Fondsproxy ist kein freigegebenes Symbol konfiguriert.', 'SYMBOL_NOT_CONFIGURED');
        }
        $expectedCurrency = $asset->slug === 'global_ig_bonds' ? 'EUR' : 'PTS';
        if (strtoupper($asset->rawCurrency) !== $expectedCurrency
            || strtoupper($asset->displayCurrency) !== $expectedCurrency) {
            throw new ProviderException('Die Fondsproxy-Waehrung stimmt nicht mit der Datenreihe ueberein.', 'CURRENCY_MISMATCH');
        }
        return $series;
    }

    /**
     * @param array<string, array{date: DateTimeImmutable, timestamp: DateTimeImmutable, value: float}> $rawRows
     * @param array<string, string> $usdPerEurByDate
     * @return array<string, array{date: string, rate: string}>
     */
    private static function ratesAtObservationDates(array $rawRows, array $usdPerEurByDate, string $sourceCurrency): array
    {
        if ($sourceCurrency === 'EUR') {
            return [];
        }
        if ($usdPerEurByDate === []) {
            throw new ProviderException('Fuer den Fondsproxy fehlen EZB-Wechselkurse.', 'FX_RATE_MISSING');
        }
        ksort($usdPerEurByDate);
        ksort($rawRows);
        $rateDates = array_keys($usdPerEurByDate);
        $cursor = 0;
        $currentDate = null;
        $currentRate = null;
        $mapped = [];
        foreach (array_keys($rawRows) as $dateText) {
            while ($cursor < count($rateDates) && $rateDates[$cursor] <= $dateText) {
                $currentDate = $rateDates[$cursor];
                $currentRate = $usdPerEurByDate[$currentDate];
                $cursor++;
            }
            if ($currentDate === null || !is_numeric($currentRate) || (float) $currentRate <= 0) {
                continue;
            }
            $mapped[$dateText] = ['date' => $currentDate, 'rate' => (string) $currentRate];
        }
        return $mapped;
    }

    private static function sourceEurValue(float $value, string $sourceCurrency, mixed $usdPerEur): float
    {
        if ($sourceCurrency === 'EUR') {
            return $value;
        }
        if (!is_numeric($usdPerEur) || (float) $usdPerEur <= 0) {
            throw new ProviderException('Am Handelstag fehlt ein verwendbarer EZB-Referenzkurs.', 'FX_RATE_MISSING');
        }
        return $value / (float) $usdPerEur;
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
