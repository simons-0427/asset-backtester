<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class FredProvider
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
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new ProviderException('FRED-API-Key fehlt in der Serverkonfiguration.', 'PROVIDER_NOT_CONFIGURED');
        }
        if (!preg_match('/^[a-z0-9]{32}$/', $apiKey)) {
            throw new ProviderException('Der FRED-API-Key hat nicht das erwartete Format.', 'PROVIDER_CONFIG_INVALID');
        }
        if ($asset->providerSymbol !== 'SP500') {
            throw new ProviderException('Für den S&P 500 muss die FRED-Reihe SP500 konfiguriert sein.', 'SYMBOL_NOT_CONFIGURED');
        }

        $baseUrl = (string) ($this->config['base_url']
            ?? 'https://api.stlouisfed.org/fred/series/observations');
        $query = http_build_query([
            'series_id' => $asset->providerSymbol,
            'api_key' => $apiKey,
            'file_type' => 'json',
            'observation_start' => $from->format('Y-m-d'),
            'observation_end' => $to->format('Y-m-d'),
            'sort_order' => 'asc',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::parseJson($this->http->get(rtrim($baseUrl, '?') . '?' . $query), $asset, $from, $to);
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
                    'Ungültiges FRED-Live-Startdatum in der Serverkonfiguration.',
                    'PROVIDER_CONFIG_INVALID',
                );
            }
            if ($from < $minimum) {
                $from = $minimum;
            }
        }

        if ($from > $to) {
            throw new ProviderException(
                'Für FRED ist noch kein abgeschlossener Live-Tag verfügbar.',
                'LIVE_RANGE_NOT_STARTED',
            );
        }
        return [$from, $to];
    }

    public static function parseJson(
        string $json,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new ProviderException('Unerwartetes Antwortformat von FRED.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        if (isset($payload['error_code']) || isset($payload['error_message'])) {
            throw new ProviderException('FRED hat die Anfrage abgelehnt.', 'PROVIDER_REJECTED_REQUEST');
        }
        if (!isset($payload['observations']) || !is_array($payload['observations'])) {
            throw new ProviderException('In der FRED-Antwort fehlen die Beobachtungen.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $utc = new DateTimeZone('UTC');
        $observations = [];
        foreach ($payload['observations'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dateText = (string) ($row['date'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $utc);
            if (
                $date === false
                || $date->format('Y-m-d') !== $dateText
                || $dateText < $fromDate
                || $dateText > $toDate
                || !is_numeric($value)
                || (float) $value <= 0
            ) {
                continue;
            }

            $revision = sprintf(
                'fred:%s:%s:%s',
                $asset->providerSymbol,
                $dateText,
                (string) ($row['realtime_start'] ?? 'current'),
            );
            $observations[] = new HistoricalObservation(
                $date,
                $value,
                $asset->rawCurrency,
                null,
                'valid',
                substr($revision, 0, 100),
                hash('sha256', (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            );
        }

        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }
}
