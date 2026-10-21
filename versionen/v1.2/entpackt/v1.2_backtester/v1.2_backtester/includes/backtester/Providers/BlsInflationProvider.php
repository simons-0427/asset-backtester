<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

/** Loads the unadjusted monthly U.S. CPI-U from the free BLS public API. */
final class BlsInflationProvider implements HistoricalDataProviderInterface
{
    private const SYMBOL = 'CUUR0000SA0';

    private readonly HttpClient $http;

    public function __construct(private readonly array $config = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 1),
            (int) ($config['retry_base_ms'] ?? 750),
            (bool) ($config['prefer_php_stream'] ?? true),
        );
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        self::assertAsset($asset);
        $baseUrl = (string) ($this->config['base_url']
            ?? 'https://api.bls.gov/publicAPI/v2/timeseries/data');
        // The unregistered public endpoint accepts a limited year span. The
        // controlled CSV already contains older history, so live calls only
        // need the requested recent years.
        $endYear = (int) $to->format('Y');
        $startYear = max((int) $from->format('Y'), $endYear - 9);
        $query = http_build_query([
            'startyear' => (string) $startYear,
            'endyear' => (string) $endYear,
        ], '', '&', PHP_QUERY_RFC3986);
        $url = rtrim($baseUrl, '/') . '/' . rawurlencode($asset->providerSymbol) . '?' . $query;
        return self::parseJson($this->http->get($url), $asset, $from, $to);
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        $utc = new DateTimeZone('UTC');
        $to = new DateTimeImmutable('today', $utc);
        $revisionDays = (int) ($this->config['revision_lookback_days'] ?? 450);
        $from = $to->modify(sprintf('-%d days', max($lookbackDays, $revisionDays)));
        return $this->fetchRange($asset, $from, $to);
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->slug === 'inflation_us'
            && $asset->providerType === 'bls_public_api';
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
        if (!is_array($payload)
            || ($payload['status'] ?? null) !== 'REQUEST_SUCCEEDED'
            || !is_array($payload['Results']['series'] ?? null)) {
            throw new ProviderException('Unerwartetes Antwortformat der BLS-Inflationsreihe.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $series = null;
        foreach ($payload['Results']['series'] as $candidate) {
            if (is_array($candidate) && ($candidate['seriesID'] ?? null) === self::SYMBOL) {
                $series = $candidate;
                break;
            }
        }
        if (!is_array($series) || !is_array($series['data'] ?? null)) {
            throw new ProviderException('BLS lieferte nicht die freigegebene CPI-U-Reihe.', 'SYMBOL_MISMATCH');
        }

        $utc = new DateTimeZone('UTC');
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $observations = [];
        foreach ($series['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $year = (string) ($row['year'] ?? '');
            $period = (string) ($row['period'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));
            if (preg_match('/^\d{4}$/', $year) !== 1
                || preg_match('/^M(0[1-9]|1[0-2])$/', $period, $matches) !== 1
                || !is_numeric($value)
                || (float) $value <= 0) {
                continue;
            }
            $month = $year . '-' . $matches[1];
            $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $utc);
            if ($monthStart === false) {
                continue;
            }
            $date = $monthStart->modify('last day of this month');
            $dateText = $date->format('Y-m-d');
            if ($dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $decimal = self::decimal((float) $value, 6);
            $latest = filter_var($row['latest'] ?? false, FILTER_VALIDATE_BOOL);
            $observations[] = new HistoricalObservation(
                $date,
                $decimal,
                'PTS',
                null,
                $latest ? 'provisional' : 'valid',
                substr('bls-cpi-u:' . self::SYMBOL . ':' . $month . ($latest ? ':latest' : ''), 0, 100),
                hash('sha256', $month . '|' . $decimal . '|' . ($latest ? '1' : '0')),
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
        if ($asset->providerSymbol !== self::SYMBOL) {
            throw new ProviderException('Fuer die US-Inflation muss CPI-U CUUR0000SA0 konfiguriert sein.', 'SYMBOL_NOT_CONFIGURED');
        }
        if (strtoupper($asset->rawCurrency) !== 'PTS' || strtoupper($asset->displayCurrency) !== 'PTS') {
            throw new ProviderException('Der US-Inflationsindex muss als Punktreihe konfiguriert sein.', 'CURRENCY_MISMATCH');
        }
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
