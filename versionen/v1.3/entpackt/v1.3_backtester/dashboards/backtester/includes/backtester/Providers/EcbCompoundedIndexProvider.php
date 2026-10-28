<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

/** Extends the imported Euro money-market model with the official compounded EURSTR index. */
final class EcbCompoundedIndexProvider implements HistoricalDataProviderInterface
{
    private const SYMBOL = 'EST.B.EU000A2QQF08.CI';
    private const ANCHOR_DATE = '2026-08-19';
    private const ANCHOR_VALUE = 108.152193;

    private readonly HttpClient $http;

    public function __construct(private readonly array $config = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 2),
            (int) ($config['retry_base_ms'] ?? 750),
            (bool) ($config['prefer_php_stream'] ?? true),
        );
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        self::assertAsset($asset);
        $baseUrl = (string) ($this->config['estr_index_url']
            ?? 'https://data-api.ecb.europa.eu/service/data/EST/B.EU000A2QQF08.CI');
        $query = http_build_query([
            'startPeriod' => self::ANCHOR_DATE,
            'endPeriod' => $to->format('Y-m-d'),
            'format' => 'csvdata',
        ], '', '&', PHP_QUERY_RFC3986);
        return self::parseCsv($this->http->get(rtrim($baseUrl, '?') . '?' . $query), $asset, $from, $to);
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
        return $asset->slug === 'euro_money_market'
            && $asset->providerType === 'ecb_compounded_index';
    }

    /** @return list<HistoricalObservation> */
    public static function parseCsv(
        string $csv,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        self::assertAsset($asset);
        $trimmed = trim($csv);
        if ($trimmed === '' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '<')) {
            throw new ProviderException('Unerwartetes Antwortformat des EURSTR-Index.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $lines = preg_split('/\R/', $trimmed) ?: [];
        $headers = array_map(
            static fn (string $header): string => ltrim(trim($header), "\xEF\xBB\xBF"),
            str_getcsv((string) array_shift($lines), ',', '"', '\\'),
        );
        foreach (['KEY', 'FREQ', 'BENCHMARK_ITEM', 'DATA_TYPE_EST', 'TIME_PERIOD', 'OBS_VALUE', 'OBS_STATUS'] as $field) {
            if (!in_array($field, $headers, true)) {
                throw new ProviderException('In der EURSTR-Antwort fehlen Pflichtfelder.', 'PROVIDER_SCHEMA_MISMATCH');
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $row = array_combine($headers, array_pad($values, count($headers), null));
            if ($row === false
                || ($row['KEY'] ?? '') !== self::SYMBOL
                || ($row['FREQ'] ?? '') !== 'B'
                || ($row['BENCHMARK_ITEM'] ?? '') !== 'EU000A2QQF08'
                || ($row['DATA_TYPE_EST'] ?? '') !== 'CI') {
                continue;
            }
            $dateText = (string) ($row['TIME_PERIOD'] ?? '');
            $value = trim((string) ($row['OBS_VALUE'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText) !== 1
                || !is_numeric($value)
                || (float) $value <= 0) {
                continue;
            }
            $rows[$dateText] = [
                'value' => (float) $value,
                'status' => strtoupper(trim((string) ($row['OBS_STATUS'] ?? ''))),
            ];
        }
        if (!isset($rows[self::ANCHOR_DATE])) {
            throw new ProviderException('Der EURSTR-Abruf enthaelt den festen Anschlusswert nicht.', 'ANCHOR_MISSING');
        }

        $anchor = $rows[self::ANCHOR_DATE]['value'];
        $fromDate = $from->format('Y-m-d') < self::ANCHOR_DATE ? self::ANCHOR_DATE : $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $utc = new DateTimeZone('UTC');
        $observations = [];
        foreach ($rows as $dateText => $row) {
            if ($dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $utc);
            if ($date === false) {
                continue;
            }
            $decimal = self::decimal(self::ANCHOR_VALUE * $row['value'] / $anchor, 10);
            $observations[] = new HistoricalObservation(
                $date,
                $decimal,
                'PTS',
                null,
                $row['status'] === 'P' ? 'provisional' : 'valid',
                substr('ecb-estr-index:' . $dateText . ':' . $row['status'], 0, 100),
                hash('sha256', $dateText . '|' . $row['value'] . '|' . $row['status']),
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
            throw new ProviderException('Fuer den Euro-Geldmarkt muss der offizielle EURSTR-Index konfiguriert sein.', 'SYMBOL_NOT_CONFIGURED');
        }
        if (strtoupper($asset->rawCurrency) !== 'PTS' || strtoupper($asset->displayCurrency) !== 'PTS') {
            throw new ProviderException('Der Euro-Geldmarkt muss als Punktreihe konfiguriert sein.', 'CURRENCY_MISMATCH');
        }
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
