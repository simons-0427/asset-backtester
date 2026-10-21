<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class GoldApiProvider
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
            throw new ProviderException('GoldAPI-API-Key fehlt in der Serverkonfiguration.', 'PROVIDER_NOT_CONFIGURED');
        }
        if (strlen($apiKey) < 8 || strlen($apiKey) > 256 || preg_match('/\s/', $apiKey)) {
            throw new ProviderException('Der GoldAPI-API-Key hat nicht das erwartete Format.', 'PROVIDER_CONFIG_INVALID');
        }

        $metal = strtoupper(trim($asset->providerSymbol));
        $currency = strtoupper(trim((string) ($asset->providerDataset ?: $asset->rawCurrency)));
        if ($metal !== 'XAU' || $currency !== 'USD' || strtoupper($asset->rawCurrency) !== 'USD') {
            throw new ProviderException(
                'GoldAPI ist für die konfigurierte Reihe nur als XAU/USD eingerichtet.',
                'PROVIDER_CONFIG_INVALID',
            );
        }

        $start = new DateTimeImmutable($from->format('Y-m-d'), new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($to->format('Y-m-d'), new DateTimeZone('UTC'));

        $liveUpdatesFrom = trim((string) ($this->config['live_updates_from'] ?? ''));
        if ($liveUpdatesFrom !== '') {
            $utc = new DateTimeZone('UTC');
            $minimum = DateTimeImmutable::createFromFormat('!Y-m-d', $liveUpdatesFrom, $utc);
            $errors = DateTimeImmutable::getLastErrors();
            if (
                $minimum === false
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $minimum->format('Y-m-d') !== $liveUpdatesFrom
            ) {
                throw new ProviderException(
                    'Ungueltiges GoldAPI-Live-Startdatum in der Serverkonfiguration.',
                    'PROVIDER_CONFIG_INVALID',
                );
            }
            if ($start < $minimum) {
                $start = $minimum;
            }
        }

        if ($start > $end) {
            return [];
        }

        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'https://www.goldapi.io/api'), '/');
        $observations = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            if ($asset->calendarType === 'market_weekdays' && (int) $day->format('N') > 5) {
                continue;
            }

            $dateText = $day->format('Ymd');
            $url = sprintf(
                '%s/%s/%s/%s',
                $baseUrl,
                rawurlencode($metal),
                rawurlencode($currency),
                $dateText,
            );
            $payload = $this->http->get($url, ['x-access-token: ' . $apiKey]);
            $observation = self::parseResponse($payload, $asset, $day);
            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        $window = min(
            max(1, $lookbackDays),
            max(1, (int) ($this->config['recent_window_days'] ?? 3)),
        );
        [$from, $to] = self::recentRange(
            $window,
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
                    'Ungültiges GoldAPI-Live-Startdatum in der Serverkonfiguration.',
                    'PROVIDER_CONFIG_INVALID',
                );
            }
            if ($from < $minimum) {
                $from = $minimum;
            }
        }

        return [$from, $to];
    }

    public static function parseResponse(
        string $json,
        AssetDefinition $asset,
        DateTimeImmutable $requestedDate,
    ): ?HistoricalObservation {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new ProviderException('Unerwartetes Antwortformat von GoldAPI.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $providerMessage = (string) ($payload['error'] ?? $payload['message'] ?? $payload['error_message'] ?? '');
        if ($providerMessage !== '') {
            [$code, $message] = self::describeRejection($providerMessage);
            throw new ProviderException($message, $code);
        }

        $metal = strtoupper((string) ($payload['metal'] ?? ''));
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        if ($metal !== strtoupper($asset->providerSymbol) || $currency !== strtoupper($asset->rawCurrency)) {
            throw new ProviderException('GoldAPI-Antwort enthält nicht XAU/USD.', 'CURRENCY_MISMATCH');
        }

        $value = $payload['price'] ?? $payload['close'] ?? $payload['prev_close_price'] ?? null;
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new ProviderException('In der GoldAPI-Antwort fehlt ein gültiger Goldpreis.', 'PROVIDER_SCHEMA_MISMATCH');
        }

        $sourceTimestamp = null;
        if (isset($payload['timestamp']) && is_numeric($payload['timestamp'])) {
            $timestamp = (int) $payload['timestamp'];
            if ($timestamp > 0) {
                $sourceTimestamp = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
            }
        }

        $date = new DateTimeImmutable($requestedDate->format('Y-m-d'), new DateTimeZone('UTC'));
        $dateText = $date->format('Y-m-d');
        $revision = sprintf('goldapi:%s/%s:%s', $metal, $currency, $dateText);
        return new HistoricalObservation(
            $date,
            (string) $value,
            $currency,
            $sourceTimestamp,
            'valid',
            substr($revision, 0, 100),
            hash('sha256', $json),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function describeRejection(string $providerMessage): array
    {
        $message = strtolower($providerMessage);
        if (
            str_contains($message, 'rate limit')
            || str_contains($message, 'quota')
            || str_contains($message, 'too many')
            || str_contains($message, 'monthly limit')
        ) {
            return ['RATE_LIMITED', 'Das GoldAPI-Abruflimit wurde erreicht.'];
        }
        if (
            str_contains($message, 'invalid')
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'api key')
            || str_contains($message, 'access token')
        ) {
            return ['PROVIDER_KEY_INVALID', 'GoldAPI hat den API-Key als ungültig oder fehlend gemeldet.'];
        }
        if (
            str_contains($message, 'historical')
            || str_contains($message, 'subscription')
            || str_contains($message, 'plan')
        ) {
            return ['PREMIUM_REQUIRED', 'GoldAPI erlaubt den historischen Abruf mit dem aktuellen Tarif nicht.'];
        }
        return ['PROVIDER_REJECTED_REQUEST', 'GoldAPI hat die Anfrage abgelehnt.'];
    }
}
