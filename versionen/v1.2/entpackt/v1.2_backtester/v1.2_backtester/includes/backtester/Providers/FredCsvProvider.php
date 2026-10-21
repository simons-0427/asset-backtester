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
 * Reads a narrowly configured public FRED graph CSV without an API key.
 *
 * The provider keeps the source observation date. A daily job therefore checks
 * for new monthly values without inventing daily house-price observations.
 */
final class FredCsvProvider implements HistoricalDataProviderInterface
{
    private readonly HttpClient $http;

    public function __construct(private readonly array $config = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 3),
            (int) ($config['retry_base_ms'] ?? 500),
            (bool) ($config['prefer_php_stream'] ?? false),
        );
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        self::assertAsset($asset);
        $baseUrl = (string) ($this->config['base_url']
            ?? 'https://fred.stlouisfed.org/graph/fredgraph.csv');
        $query = http_build_query([
            'id' => $asset->providerSymbol,
            'cosd' => $from->format('Y-m-d'),
            'coed' => $to->format('Y-m-d'),
        ], '', '&', PHP_QUERY_RFC3986);

        return self::parseCsv(
            $this->http->get(rtrim($baseUrl, '?') . '?' . $query),
            $asset,
            $from,
            $to,
        );
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        $utc = new DateTimeZone('UTC');
        $to = new DateTimeImmutable('today', $utc);
        $configuredDays = (int) ($this->config['revision_lookback_days'] ?? 450);
        $from = $to->modify(sprintf('-%d days', max($lookbackDays, $configuredDays)));
        return $this->fetchRange($asset, $from, $to);
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->slug === 'us_real_estate' && $asset->providerType === 'fred_csv';
    }

    public static function parseCsv(
        string $csv,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        self::assertAsset($asset);
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $headers = array_map(
            static fn (string $header): string => ltrim(trim($header), "\xEF\xBB\xBF"),
            str_getcsv((string) array_shift($lines), ',', '"', '\\'),
        );
        if (!in_array('observation_date', $headers, true)
            || !in_array($asset->providerSymbol, $headers, true)) {
            throw new ProviderException('FRED-CSV enthält nicht die erwartete US-Immobilienreihe.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $utc = new DateTimeZone('UTC');
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $observations = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $row = array_combine($headers, array_pad($values, count($headers), null));
            if ($row === false) {
                continue;
            }
            $dateText = trim((string) ($row['observation_date'] ?? ''));
            $value = trim((string) ($row[$asset->providerSymbol] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $utc);
            if ($date === false
                || $date->format('Y-m-d') !== $dateText
                || $dateText < $fromDate
                || $dateText > $toDate
                || !is_numeric($value)
                || (float) $value <= 0) {
                continue;
            }

            $revision = sprintf('fred-csv:%s:%s', $asset->providerSymbol, $dateText);
            $observations[] = new HistoricalObservation(
                $date,
                $value,
                $asset->rawCurrency,
                null,
                'valid',
                substr($revision, 0, 100),
                hash('sha256', $line),
                $value,
                '1',
                $date,
            );
        }

        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    private static function assertAsset(AssetDefinition $asset): void
    {
        if ($asset->providerSymbol !== 'HPIPONM226S') {
            throw new ProviderException('Für Immobilien USA muss die FHFA-Reihe HPIPONM226S konfiguriert sein.', 'SYMBOL_NOT_CONFIGURED');
        }
    }
}
