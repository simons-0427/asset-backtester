<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class AlphaVantageProvider
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
            throw new ProviderException('Alpha-Vantage-API-Key fehlt in der Serverkonfiguration.', 'PROVIDER_NOT_CONFIGURED');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $apiKey)) {
            throw new ProviderException(
                'Der Alpha-Vantage-API-Key hat nicht das erwartete Format. In api_key darf nur der Schlüssel stehen.',
                'PROVIDER_CONFIG_INVALID',
            );
        }
        if (str_starts_with($asset->providerSymbol, 'CONFIGURE_')) {
            throw new ProviderException('Das Produktionssymbol für ' . $asset->displayName . ' wurde noch nicht festgelegt.', 'SYMBOL_NOT_CONFIGURED');
        }

        $function = $asset->providerDataset ?: 'TIME_SERIES_DAILY';
        if ($function !== 'TIME_SERIES_DAILY') {
            throw new ProviderException('Die kostenlose Konfiguration erlaubt nur Alpha Vantage TIME_SERIES_DAILY.', 'PROVIDER_CONFIG_INVALID');
        }

        $parameters = [
            'function' => $function,
            'symbol' => $asset->providerSymbol,
            'datatype' => 'csv',
            'apikey' => $apiKey,
        ];
        $parameters['outputsize'] = 'compact';
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        $csv = $this->http->get(rtrim((string) $this->config['base_url'], '?') . '?' . $query);
        return self::parseCsv($csv, $asset, $from, $to);
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
                    'Ungültiges Alpha-Vantage-Live-Startdatum in der Serverkonfiguration.',
                    'PROVIDER_CONFIG_INVALID',
                );
            }
            if ($from < $minimum) {
                $from = $minimum;
            }
        }

        if ($from > $to) {
            throw new ProviderException(
                'Für Alpha Vantage ist noch kein abgeschlossener Live-Tag verfügbar.',
                'LIVE_RANGE_NOT_STARTED',
            );
        }
        return [$from, $to];
    }

    public static function parseCsv(
        string $csv,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $trimmed = trim($csv);
        if ($trimmed === '') {
            return [];
        }
        if (str_starts_with($trimmed, '{')) {
            $payload = json_decode($trimmed, true);
            $message = (string) ($payload['Note'] ?? $payload['Information'] ?? $payload['Error Message'] ?? '');
            [$code, $safeMessage] = self::describeRejection($message);
            throw new ProviderException(
                $message !== '' ? $safeMessage : 'Unerwartetes Antwortformat des Marktdatenanbieters.',
                $message !== '' ? $code : 'INVALID_PROVIDER_RESPONSE'
            );
        }

        $lines = preg_split('/\R/', $trimmed) ?: [];
        $headers = array_map(
            static fn (string $header): string => ltrim(trim($header), "\xEF\xBB\xBF"),
            str_getcsv((string) array_shift($lines), ',', '"', '\\'),
        );
        $dateIndex = array_search('timestamp', $headers, true);
        $valueIndex = array_search('close', $headers, true);
        if ($dateIndex === false || $valueIndex === false) {
            throw new ProviderException('Erforderliche Kursfelder fehlen in der Providerantwort.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $observations = [];
        $utc = new DateTimeZone('UTC');
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line, ',', '"', '\\');
            $dateText = (string) ($row[$dateIndex] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $utc);
            $value = trim((string) ($row[$valueIndex] ?? ''));
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
            $observations[] = new HistoricalObservation(
                $date,
                $value,
                $asset->rawCurrency,
                null,
                'valid',
                sprintf('alpha-vantage:%s:%s:%s', $asset->providerDataset, $asset->providerSymbol, $date->format('Y-m-d')),
                hash('sha256', $line),
            );
        }

        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    /**
     * Ordnet Alpha-Vantage-Fehler ein, ohne die möglicherweise veränderliche
     * Originalantwort oder Zugangsdaten in das Produktionslog zu übernehmen.
     *
     * @return array{0: string, 1: string}
     */
    public static function describeRejection(string $providerMessage): array
    {
        $message = strtolower($providerMessage);
        if (
            str_contains($message, '25 requests per day')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'call frequency')
        ) {
            return [
                'RATE_LIMITED',
                'Das kostenlose Alpha-Vantage-Tageslimit wurde erreicht.',
            ];
        }
        if (str_contains($message, 'premium endpoint') || str_contains($message, 'premium membership')) {
            return [
                'PREMIUM_REQUIRED',
                'Alpha Vantage bezeichnet den angefragten Endpunkt als kostenpflichtig.',
            ];
        }
        if (
            str_contains($message, 'apikey is invalid')
            || str_contains($message, 'api key is invalid')
            || (str_contains($message, 'apikey') && str_contains($message, 'invalid or missing'))
        ) {
            return [
                'PROVIDER_KEY_INVALID',
                'Alpha Vantage hat den API-Key als ungültig oder fehlend gemeldet.',
            ];
        }
        if (str_contains($message, 'invalid api call')) {
            return [
                'PROVIDER_INVALID_REQUEST',
                'Alpha Vantage hat die Anfrageparameter abgelehnt.',
            ];
        }
        return [
            'PROVIDER_REJECTED_REQUEST',
            'Der Marktdatenanbieter hat die Anfrage abgelehnt.',
        ];
    }
}
