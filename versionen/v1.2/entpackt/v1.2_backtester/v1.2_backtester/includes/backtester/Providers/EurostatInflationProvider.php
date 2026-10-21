<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

/** Loads the official monthly all-items HICP indices for Germany and the euro area. */
final class EurostatInflationProvider implements HistoricalDataProviderInterface
{
    private const GEO_BY_SLUG = [
        'inflation_de' => 'DE',
        'inflation_euro_area' => 'EA',
    ];

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
        $geo = self::assertAsset($asset);
        $baseUrl = (string) ($this->config['hicp_base_url']
            ?? 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_minr');
        $query = http_build_query([
            'lang' => 'en',
            'freq' => 'M',
            'unit' => 'I25',
            'coicop18' => 'TOTAL',
            'geo' => $geo,
            'sinceTimePeriod' => $from->modify('first day of this month')->modify('-2 months')->format('Y-m'),
            'untilTimePeriod' => $to->format('Y-m'),
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
        $revisionDays = (int) ($this->config['hicp_revision_lookback_days'] ?? 450);
        $from = $to->modify(sprintf('-%d days', max($lookbackDays, $revisionDays)));
        return $this->fetchRange($asset, $from, $to);
    }

    public function supports(AssetDefinition $asset): bool
    {
        return isset(self::GEO_BY_SLUG[$asset->slug])
            && $asset->providerType === 'eurostat_hicp_api';
    }

    /** @return list<HistoricalObservation> */
    public static function parseJson(
        string $json,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $geo = self::assertAsset($asset);
        $payload = json_decode($json, true);
        if (!is_array($payload)
            || ($payload['class'] ?? null) !== 'dataset'
            || strtoupper((string) ($payload['source'] ?? '')) !== 'ESTAT'
            || !is_array($payload['id'] ?? null)
            || !is_array($payload['size'] ?? null)
            || !is_array($payload['dimension'] ?? null)
            || !is_array($payload['value'] ?? null)) {
            throw new ProviderException('Unerwartetes Antwortformat der Eurostat-Inflationsreihe.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $ids = array_values($payload['id']);
        $sizes = array_map('intval', array_values($payload['size']));
        if (count($ids) !== count($sizes)) {
            throw new ProviderException('Eurostat lieferte eine inkonsistente Dimensionsstruktur.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $expected = ['freq' => 'M', 'unit' => 'I25', 'coicop18' => 'TOTAL', 'geo' => $geo];
        $positions = [];
        foreach ($expected as $dimensionId => $code) {
            $positions[$dimensionId] = self::categoryPosition($payload['dimension'][$dimensionId] ?? null, $code);
        }
        $timeIndex = self::categoryIndex($payload['dimension']['time'] ?? null);
        if ($timeIndex === []) {
            throw new ProviderException('In der Eurostat-Antwort fehlen Monate.', 'PROVIDER_SCHEMA_MISMATCH');
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
        foreach ($timeIndex as $month => $timePosition) {
            $dateText = self::monthEndDate((string) $month);
            if ($dateText === null || $dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $flatIndex = self::flatIndex($ids, $sizes, $positions + ['time' => $timePosition]);
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
            $observations[] = new HistoricalObservation(
                $date,
                $decimal,
                'PTS',
                $updated,
                str_contains($status, 'p') ? 'provisional' : 'valid',
                substr('eurostat-hicp:' . $geo . ':' . $month . ':' . $status, 0, 100),
                hash('sha256', $geo . '|' . $month . '|' . $decimal . '|' . $status),
                $decimal,
                '1',
                $date,
            );
        }
        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    private static function assertAsset(AssetDefinition $asset): string
    {
        $geo = self::GEO_BY_SLUG[$asset->slug] ?? null;
        $expectedSymbol = $geo === null ? null : 'prc_hicp_minr:' . $geo . ':TOTAL:I25';
        if ($geo === null || $asset->providerSymbol !== $expectedSymbol) {
            throw new ProviderException('Fuer die Inflation ist keine freigegebene Eurostat-Reihe konfiguriert.', 'SYMBOL_NOT_CONFIGURED');
        }
        if (strtoupper($asset->rawCurrency) !== 'PTS' || strtoupper($asset->displayCurrency) !== 'PTS') {
            throw new ProviderException('Der Inflationsindex muss als Punktreihe konfiguriert sein.', 'CURRENCY_MISMATCH');
        }
        return $geo;
    }

    /** @return array<string, int> */
    private static function categoryIndex(mixed $dimension): array
    {
        if (!is_array($dimension)) {
            return [];
        }
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
        $index = self::categoryIndex($dimension);
        if (!array_key_exists($code, $index)) {
            throw new ProviderException('Eurostat lieferte eine andere Inflationsabgrenzung.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        return $index[$code];
    }

    /** @param list<string> $ids @param list<int> $sizes @param array<string, int> $coordinates */
    private static function flatIndex(array $ids, array $sizes, array $coordinates): int
    {
        $flat = 0;
        foreach ($ids as $offset => $id) {
            $id = (string) $id;
            if (!array_key_exists($id, $coordinates)) {
                throw new ProviderException('Eurostat lieferte eine unbekannte Dimension.', 'PROVIDER_SCHEMA_MISMATCH');
            }
            $position = $coordinates[$id];
            if ($position < 0 || $position >= $sizes[$offset]) {
                throw new ProviderException('Eurostat lieferte einen ungueltigen Dimensionsindex.', 'PROVIDER_SCHEMA_MISMATCH');
            }
            $stride = 1;
            for ($next = $offset + 1, $count = count($sizes); $next < $count; $next++) {
                $stride *= $sizes[$next];
            }
            $flat += $position * $stride;
        }
        return $flat;
    }

    private static function monthEndDate(string $month): ?string
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $utc);
        return $date === false ? null : $date->modify('last day of this month')->format('Y-m-d');
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
