<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

/** Extends the imported USD money-market model with the official NY Fed SOFR Index. */
final class NyFedSofrIndexProvider implements HistoricalDataProviderInterface
{
    private const SYMBOL = 'NYFED:SOFRAI';
    private const ANCHOR_DATE = '2026-08-19';
    private const ANCHOR_VALUE = 120.01127877;

    private readonly HttpClient $http;
    private readonly EcbExchangeRateProvider $exchangeRates;

    public function __construct(private readonly array $config = [], array $ecbConfig = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 2),
            (int) ($config['retry_base_ms'] ?? 750),
            (bool) ($config['prefer_php_stream'] ?? true),
        );
        $this->exchangeRates = new EcbExchangeRateProvider($ecbConfig);
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        self::assertAsset($asset);
        $baseUrl = (string) ($this->config['sofr_index_url'] ?? 'https://markets.newyorkfed.org/read');
        $query = http_build_query([
            'productCode' => '50',
            'eventCodes' => '525',
            'limit' => '10000',
            'startPosition' => '0',
            'sort' => 'postDt:-1',
            'format' => 'csv',
        ], '', '&', PHP_QUERY_RFC3986);
        $utc = new DateTimeZone('UTC');
        $rates = $this->exchangeRates->fetchUsdPerEur(
            (new DateTimeImmutable(self::ANCHOR_DATE, $utc))->modify('-10 days'),
            $to,
        );
        return self::parseCsv(
            $this->http->get(rtrim($baseUrl, '?') . '?' . $query),
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
        return $asset->slug === 'us_money_market'
            && $asset->providerType === 'nyfed_sofr_index_eur';
    }

    /**
     * @param array<string, string> $usdPerEurByDate
     * @return list<HistoricalObservation>
     */
    public static function parseCsv(
        string $csv,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $usdPerEurByDate,
    ): array {
        self::assertAsset($asset);
        $trimmed = trim($csv);
        if ($trimmed === '' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '<')) {
            throw new ProviderException('Unerwartetes Antwortformat des SOFR-Index.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $lines = preg_split('/\R/', $trimmed) ?: [];
        $headers = array_map(
            static fn (string $header): string => ltrim(trim($header), "\xEF\xBB\xBF"),
            str_getcsv((string) array_shift($lines), ',', '"', '\\'),
        );
        foreach (['Effective Date', 'Rate Type', 'SOFR Index', 'Revision Indicator (Y/N)'] as $field) {
            if (!in_array($field, $headers, true)) {
                throw new ProviderException('In der SOFR-Antwort fehlen Pflichtfelder.', 'PROVIDER_SCHEMA_MISMATCH');
            }
        }

        $utc = new DateTimeZone('UTC');
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $row = array_combine($headers, array_pad($values, count($headers), null));
            if ($row === false || strtoupper(trim((string) ($row['Rate Type'] ?? ''))) !== 'SOFRAI') {
                continue;
            }
            $sourceDate = DateTimeImmutable::createFromFormat('!m/d/Y', trim((string) ($row['Effective Date'] ?? '')), $utc);
            $value = trim((string) ($row['SOFR Index'] ?? ''));
            if ($sourceDate === false || !is_numeric($value) || (float) $value <= 0) {
                continue;
            }
            $dateText = $sourceDate->format('Y-m-d');
            $rows[$dateText] = [
                'date' => $sourceDate,
                'value' => (float) $value,
                'revised' => strtoupper(trim((string) ($row['Revision Indicator (Y/N)'] ?? ''))) === 'Y',
            ];
        }
        if (!isset($rows[self::ANCHOR_DATE])) {
            throw new ProviderException('Der SOFR-Abruf enthaelt den festen Anschlusswert nicht.', 'ANCHOR_MISSING');
        }
        $mappedRates = self::ratesAtObservationDates($rows, $usdPerEurByDate);
        if (!isset($mappedRates[self::ANCHOR_DATE])) {
            throw new ProviderException('Am SOFR-Anschlusswert fehlt der EZB-Referenzkurs.', 'FX_RATE_MISSING');
        }
        $anchorEur = $rows[self::ANCHOR_DATE]['value'] / (float) $mappedRates[self::ANCHOR_DATE]['rate'];

        $fromDate = $from->format('Y-m-d') < self::ANCHOR_DATE ? self::ANCHOR_DATE : $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $observations = [];
        foreach ($rows as $dateText => $row) {
            if ($dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $rate = $mappedRates[$dateText] ?? null;
            if ($rate === null) {
                throw new ProviderException('Am SOFR-Handelstag fehlt der EZB-Referenzkurs.', 'FX_RATE_MISSING');
            }
            $sourceEur = $row['value'] / (float) $rate['rate'];
            $decimal = self::decimal(self::ANCHOR_VALUE * $sourceEur / $anchorEur, 10);
            $revision = 'nyfed-sofr-index:' . $dateText . ';ecb-usd-eur:' . $rate['date'];
            $observations[] = new HistoricalObservation(
                $row['date'],
                $decimal,
                'PTS',
                null,
                $row['revised'] ? 'revised' : 'valid',
                substr($revision, 0, 100),
                hash('sha256', implode('|', [
                    $dateText,
                    $row['value'],
                    $rate['date'],
                    $rate['rate'],
                    $row['revised'] ? 'Y' : 'N',
                ])),
                $decimal,
                '1',
                new DateTimeImmutable($rate['date'], $utc),
            );
        }
        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    private static function assertAsset(AssetDefinition $asset): void
    {
        if ($asset->providerSymbol !== self::SYMBOL) {
            throw new ProviderException('Fuer den US-Geldmarkt muss der offizielle SOFR-Index konfiguriert sein.', 'SYMBOL_NOT_CONFIGURED');
        }
        if (strtoupper($asset->rawCurrency) !== 'PTS' || strtoupper($asset->displayCurrency) !== 'PTS') {
            throw new ProviderException('Der US-Geldmarkt muss als EUR-Punktreihe konfiguriert sein.', 'CURRENCY_MISMATCH');
        }
    }

    /**
     * @param array<string, array{date: DateTimeImmutable, value: float, revised: bool}> $rows
     * @param array<string, string> $usdPerEurByDate
     * @return array<string, array{date: string, rate: string}>
     */
    private static function ratesAtObservationDates(array $rows, array $usdPerEurByDate): array
    {
        if ($usdPerEurByDate === []) {
            throw new ProviderException('Fuer den SOFR-Index fehlen EZB-Wechselkurse.', 'FX_RATE_MISSING');
        }
        ksort($rows);
        ksort($usdPerEurByDate);
        $rateDates = array_keys($usdPerEurByDate);
        $cursor = 0;
        $currentDate = null;
        $currentRate = null;
        $mapped = [];
        foreach (array_keys($rows) as $dateText) {
            while ($cursor < count($rateDates) && $rateDates[$cursor] <= $dateText) {
                $currentDate = $rateDates[$cursor];
                $currentRate = $usdPerEurByDate[$currentDate];
                $cursor++;
            }
            if ($currentDate !== null && is_numeric($currentRate) && (float) $currentRate > 0) {
                $mapped[$dateText] = ['date' => $currentDate, 'rate' => (string) $currentRate];
            }
        }
        return $mapped;
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
