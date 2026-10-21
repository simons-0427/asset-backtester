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
 * Loads recent European price-index observations from Yahoo's chart response.
 * Historical observations stay in the imported CSV; the daily job only
 * overlaps the recent window so provisional closes and corrections can update.
 */
final class YahooIndexProvider implements HistoricalDataProviderInterface
{
    private const SYMBOLS = [
        'dax' => '^GDAXIP',
        'eurostoxx50' => '^STOXX50E',
    ];

    private readonly HttpClient $http;

    public function __construct(private readonly array $config = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 2),
            (int) ($config['retry_base_ms'] ?? 750),
            (bool) ($config['prefer_php_stream'] ?? false),
        );
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (array_key_exists('enabled', $this->config) && !(bool) $this->config['enabled']) {
            throw new ProviderException('Der automatische europäische Indexabruf ist deaktiviert.', 'PROVIDER_NOT_CONFIGURED');
        }
        self::assertAsset($asset);
        $template = (string) ($this->config['url_template']
            ?? 'https://query1.finance.yahoo.com/v8/finance/chart/%s');
        $url = sprintf($template, rawurlencode($asset->providerSymbol));
        $query = http_build_query([
            'range' => '1mo',
            'interval' => '1d',
            'events' => 'history',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::parseJson(
            $this->http->get(rtrim($url, '?') . '?' . $query),
            $asset,
            $from,
            $to,
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
        return isset(self::SYMBOLS[$asset->slug])
            && $asset->providerType === 'yahoo_chart_scraper';
    }

    /** @return list<HistoricalObservation> */
    public static function parseJson(
        string $json,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        self::assertAsset($asset);
        $payload = json_decode($json, true);
        $result = $payload['chart']['result'][0] ?? null;
        if (!is_array($result) || !is_array($result['timestamp'] ?? null)) {
            throw new ProviderException('Unerwartetes Antwortformat für den europäischen Kursindex.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $closes = $result['indicators']['quote'][0]['close'] ?? null;
        if (!is_array($closes)) {
            throw new ProviderException('In der Indexantwort fehlen Schlusswerte.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $returnedSymbol = (string) ($result['meta']['symbol'] ?? '');
        if ($returnedSymbol !== $asset->providerSymbol) {
            throw new ProviderException('Der Indexabruf lieferte ein anderes Symbol.', 'SYMBOL_MISMATCH');
        }

        $timezoneName = (string) ($result['meta']['exchangeTimezoneName'] ?? $asset->timezoneName ?? 'Europe/Berlin');
        try {
            $marketTimezone = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new ProviderException('Ungültige Zeitzone in der Indexantwort.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $today = (new DateTimeImmutable('today', $marketTimezone))->format('Y-m-d');
        $observations = [];
        foreach ($result['timestamp'] as $index => $timestamp) {
            $value = $closes[$index] ?? null;
            if (!is_numeric($timestamp) || !is_numeric($value) || (float) $value <= 0) {
                continue;
            }
            $sourceTimestamp = new DateTimeImmutable('@' . (string) $timestamp);
            $date = $sourceTimestamp->setTimezone($marketTimezone)->setTime(0, 0);
            $dateText = $date->format('Y-m-d');
            if ($dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $decimal = self::decimal((float) $value, 6);
            $revision = substr('yahoo-chart:' . $returnedSymbol . ':' . $dateText, 0, 100);
            $observations[] = new HistoricalObservation(
                $date,
                $decimal,
                'PTS',
                $sourceTimestamp,
                $dateText === $today ? 'provisional' : 'valid',
                $revision,
                hash('sha256', $dateText . '|' . $decimal . '|' . $returnedSymbol),
                $decimal,
                '1',
                $date,
            );
        }
        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    private static function assertAsset(AssetDefinition $asset): void
    {
        $symbol = self::SYMBOLS[$asset->slug] ?? null;
        if ($symbol === null || $asset->providerSymbol !== $symbol) {
            throw new ProviderException('Für den europäischen Kursindex ist kein freigegebenes Symbol konfiguriert.', 'SYMBOL_NOT_CONFIGURED');
        }
        if (strtoupper($asset->rawCurrency) !== 'PTS' || strtoupper($asset->displayCurrency) !== 'PTS') {
            throw new ProviderException('Der europäische Kursindex muss als dimensionslose Punktreihe konfiguriert sein.', 'CURRENCY_MISMATCH');
        }
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
