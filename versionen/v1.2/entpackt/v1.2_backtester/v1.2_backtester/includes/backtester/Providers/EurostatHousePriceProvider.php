<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

/** Loads the official German total-market quarterly HPI from Eurostat. */
final class EurostatHousePriceProvider implements HistoricalDataProviderInterface
{
    private readonly HttpClient $http;

    public function __construct(private readonly array $config = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 1),
            (int) ($config['retry_base_ms'] ?? 750),
            // STRATO's PHP stream cannot reliably reach the Eurostat host;
            // cURL uses the bundled current Mozilla trust store for this endpoint.
            (bool) ($config['prefer_php_stream'] ?? false),
            (string) ($config['ca_bundle_path'] ?? dirname(__DIR__, 3) . '/config/cacert.pem'),
        );
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        self::assertAsset($asset);
        $baseUrl = (string) ($this->config['base_url']
            ?? 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hpi_q');
        $query = http_build_query([
            'geo' => 'DE',
            'purchase' => 'TOTAL',
            'unit' => 'I15_Q',
            'lang' => 'en',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::parseJson(
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
        $revisionDays = (int) ($this->config['revision_lookback_days'] ?? 1100);
        $from = $to->modify(sprintf('-%d days', max($lookbackDays, $revisionDays)));
        return $this->fetchRange($asset, $from, $to);
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->slug === 'de_real_estate'
            && $asset->providerType === 'eurostat_statistics_api';
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
            || ($payload['class'] ?? null) !== 'dataset'
            || strtoupper((string) ($payload['source'] ?? '')) !== 'ESTAT'
            || !is_array($payload['id'] ?? null)
            || !is_array($payload['size'] ?? null)
            || !is_array($payload['dimension'] ?? null)
            || !is_array($payload['value'] ?? null)) {
            throw new ProviderException('Unerwartetes Antwortformat der Eurostat-Immobilienreihe.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $ids = array_values($payload['id']);
        $sizes = array_map('intval', array_values($payload['size']));
        if (count($ids) !== count($sizes)) {
            throw new ProviderException('Eurostat lieferte eine inkonsistente Dimensionsstruktur.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $expected = ['freq' => 'Q', 'purchase' => 'TOTAL', 'unit' => 'I15_Q', 'geo' => 'DE'];
        $positions = [];
        foreach ($expected as $dimensionId => $code) {
            $positions[$dimensionId] = self::categoryPosition($payload['dimension'][$dimensionId] ?? null, $code);
        }
        $timeDimension = $payload['dimension']['time'] ?? null;
        if (!is_array($timeDimension)) {
            throw new ProviderException('In der Eurostat-Antwort fehlt die Zeitdimension.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $timeIndex = self::categoryIndex($timeDimension);
        if ($timeIndex === []) {
            throw new ProviderException('In der Eurostat-Antwort fehlen Quartale.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $updated = null;
        if (is_string($payload['updated'] ?? null)) {
            try {
                $updated = new DateTimeImmutable($payload['updated']);
            } catch (\Throwable) {
                $updated = null;
            }
        }
        $statuses = is_array($payload['status'] ?? null) ? $payload['status'] : [];
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $utc = new DateTimeZone('UTC');
        $observations = [];
        foreach ($timeIndex as $quarter => $timePosition) {
            $dateText = self::quarterEndDate((string) $quarter);
            if ($dateText === null || $dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $coordinates = $positions + ['time' => $timePosition];
            $flatIndex = self::flatIndex($ids, $sizes, $coordinates);
            $value = $payload['value'][$flatIndex] ?? null;
            if (!is_numeric($value) || (float) $value <= 0) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $utc);
            if ($date === false) {
                continue;
            }
            $decimal = self::decimal((float) $value, 6);
            $status = strtolower((string) ($statuses[$flatIndex] ?? ''));
            $revision = substr('eurostat:prc_hpi_q:DE:' . $quarter, 0, 100);
            $observations[] = new HistoricalObservation(
                $date,
                $decimal,
                'PTS',
                $updated,
                str_contains($status, 'p') ? 'provisional' : 'valid',
                $revision,
                hash('sha256', $quarter . '|' . $decimal . '|' . $status),
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
        if ($asset->providerSymbol !== 'prc_hpi_q:DE:TOTAL:I15_Q') {
            throw new ProviderException('Für Immobilien DE muss die Eurostat-Gesamtmarktreihe konfiguriert sein.', 'SYMBOL_NOT_CONFIGURED');
        }
        if (strtoupper($asset->rawCurrency) !== 'PTS' || strtoupper($asset->displayCurrency) !== 'PTS') {
            throw new ProviderException('Der deutsche Immobilienindex muss als Punktreihe konfiguriert sein.', 'CURRENCY_MISMATCH');
        }
    }

    /** @return array<string, int> */
    private static function categoryIndex(array $dimension): array
    {
        $index = $dimension['category']['index'] ?? null;
        if (!is_array($index)) {
            return [];
        }
        if (array_is_list($index)) {
            $mapped = [];
            foreach ($index as $position => $code) {
                $mapped[(string) $code] = (int) $position;
            }
            return $mapped;
        }
        $mapped = [];
        foreach ($index as $code => $position) {
            if (is_numeric($position)) {
                $mapped[(string) $code] = (int) $position;
            }
        }
        return $mapped;
    }

    private static function categoryPosition(mixed $dimension, string $code): int
    {
        if (!is_array($dimension)) {
            throw new ProviderException('Eurostat lieferte nicht alle erwarteten Dimensionen.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $index = self::categoryIndex($dimension);
        if (!array_key_exists($code, $index)) {
            throw new ProviderException('Eurostat lieferte eine andere Immobilienabgrenzung.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        return $index[$code];
    }

    /** @param list<string> $ids @param list<int> $sizes @param array<string, int> $coordinates */
    private static function flatIndex(array $ids, array $sizes, array $coordinates): int
    {
        $flat = 0;
        foreach ($ids as $offset => $id) {
            if (!array_key_exists((string) $id, $coordinates)) {
                throw new ProviderException('Eurostat lieferte eine unbekannte Dimension.', 'PROVIDER_SCHEMA_MISMATCH');
            }
            $position = $coordinates[(string) $id];
            if ($position < 0 || $position >= $sizes[$offset]) {
                throw new ProviderException('Eurostat lieferte einen ungültigen Dimensionsindex.', 'PROVIDER_SCHEMA_MISMATCH');
            }
            $stride = 1;
            for ($next = $offset + 1, $count = count($sizes); $next < $count; $next++) {
                $stride *= $sizes[$next];
            }
            $flat += $position * $stride;
        }
        return $flat;
    }

    private static function quarterEndDate(string $quarter): ?string
    {
        if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches) !== 1) {
            return null;
        }
        $suffix = match ((int) $matches[2]) {
            1 => '03-31',
            2 => '06-30',
            3 => '09-30',
            4 => '12-31',
        };
        return $matches[1] . '-' . $suffix;
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
