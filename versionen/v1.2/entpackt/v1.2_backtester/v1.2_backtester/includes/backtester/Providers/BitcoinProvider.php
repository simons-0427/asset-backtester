<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class BitcoinProvider implements HistoricalDataProviderInterface
{
    private readonly HttpClient $http;

    public function __construct(private readonly array $config)
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 3),
            (int) ($config['retry_base_ms'] ?? 500),
        );
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $query = http_build_query([
            'vs_currency' => strtolower($asset->rawCurrency),
            'from' => $from->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->getTimestamp(),
            'to' => $to->setTimezone(new DateTimeZone('UTC'))->modify('+1 day')->setTime(0, 0)->getTimestamp(),
            'interval' => self::intervalForRange($from, $to),
            'precision' => 'full',
        ], '', '&', PHP_QUERY_RFC3986);
        $base = rtrim((string) $this->config['base_url'], '/');
        $url = sprintf('%s/coins/%s/market_chart/range?%s', $base, rawurlencode($asset->providerSymbol), $query);

        $headers = [];
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($key !== '') {
            $headerName = (string) ($this->config['api_key_header'] ?? 'x-cg-demo-api-key');
            if (!in_array($headerName, ['x-cg-demo-api-key', 'x-cg-pro-api-key'], true)) {
                throw new ProviderException('Ungültiger CoinGecko-Key-Header in der Serverkonfiguration.', 'PROVIDER_CONFIG_INVALID');
            }
            $headers[] = $headerName . ': ' . $key;
        }

        return self::parseJson($this->http->get($url, $headers), $asset, $from, $to);
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        [$from, $to] = self::recentRange(
            $lookbackDays,
            (string) ($this->config['live_updates_from'] ?? ''),
        );
        return $this->fetchRange($asset, $from, $to);
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function recentRange(
        int $lookbackDays,
        string $liveUpdatesFrom = '',
        ?DateTimeImmutable $today = null,
    ): array {
        $utc = new DateTimeZone('UTC');
        $today = ($today ?? new DateTimeImmutable('today', $utc))->setTimezone($utc)->setTime(0, 0);
        $to = $today->modify('-1 day');
        $from = $to->modify(sprintf('-%d days', max(1, $lookbackDays)));

        if ($liveUpdatesFrom !== '') {
            $minimum = DateTimeImmutable::createFromFormat('!Y-m-d', $liveUpdatesFrom, $utc);
            $errors = DateTimeImmutable::getLastErrors();
            if (
                $minimum === false
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $minimum->format('Y-m-d') !== $liveUpdatesFrom
            ) {
                throw new ProviderException(
                    'Ungültiges CoinGecko-Live-Startdatum in der Serverkonfiguration.',
                    'PROVIDER_CONFIG_INVALID',
                );
            }
            if ($from < $minimum) {
                $from = $minimum;
            }
        }

        if ($from > $to) {
            throw new ProviderException(
                'Für CoinGecko ist noch kein abgeschlossener Live-Tag verfügbar.',
                'LIVE_RANGE_NOT_STARTED',
            );
        }
        return [$from, $to];
    }

    public static function intervalForRange(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $days = (int) $from->diff($to)->days + 1;
        return $days <= 100 ? 'hourly' : 'daily';
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->slug === 'bitcoin' && $asset->providerType === 'coingecko';
    }

    public static function parseJson(
        string $json,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['prices']) || !is_array($payload['prices'])) {
            throw new ProviderException('Unerwartetes Antwortformat des Bitcoin-Datenanbieters.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $daily = [];
        $utc = new DateTimeZone('UTC');
        $rangeStart = new DateTimeImmutable($from->format('Y-m-d'), $utc);
        $rangeEndExclusive = (new DateTimeImmutable($to->format('Y-m-d'), $utc))->modify('+1 day');
        foreach ($payload['prices'] as $point) {
            if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }
            $timestampMs = (int) $point[0];
            $instant = (new DateTimeImmutable('@' . (int) floor($timestampMs / 1000)))->setTimezone($utc);
            $dateKey = $instant->format('Y-m-d');
            if ($instant < $rangeStart || $instant >= $rangeEndExclusive) {
                continue;
            }
            if (!isset($daily[$dateKey]) || $timestampMs > $daily[$dateKey]['timestamp']) {
                $daily[$dateKey] = [
                    'timestamp' => $timestampMs,
                    'instant' => $instant,
                    'value' => (string) $point[1],
                ];
            }
        }

        ksort($daily);
        $observations = [];
        foreach ($daily as $date => $row) {
            $hashInput = $date . '|' . $row['timestamp'] . '|' . $row['value'];
            $observations[] = new HistoricalObservation(
                new DateTimeImmutable($date, $utc),
                $row['value'],
                $asset->rawCurrency,
                $row['instant'],
                'valid',
                substr(hash('sha256', $hashInput), 0, 32),
                hash('sha256', $hashInput),
            );
        }
        return $observations;
    }
}
