<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Exceptions\ProviderException;

final class EcbExchangeRateProvider
{
    private readonly HttpClient $http;

    public function __construct(private readonly array $config = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 3),
            (int) ($config['retry_base_ms'] ?? 500),
        );
    }

    /**
     * @return array<string, string> Map of ISO date to USD per EUR.
     */
    public function fetchUsdPerEur(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $baseUrl = (string) ($this->config['base_url']
            ?? 'https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A');
        $query = http_build_query([
            'startPeriod' => $from->format('Y-m-d'),
            'endPeriod' => $to->format('Y-m-d'),
            'format' => 'csvdata',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::parseCsv($this->http->get(rtrim($baseUrl, '?') . '?' . $query), $from, $to);
    }

    /**
     * @return array<string, string> Map of ISO date to USD per EUR.
     */
    public static function parseCsv(string $csv, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $trimmed = trim($csv);
        if ($trimmed === '') {
            throw new ProviderException('Die EZB hat keine Wechselkurse geliefert.', 'EMPTY_FX_RESPONSE');
        }
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '<')) {
            throw new ProviderException('Unerwartetes Antwortformat der EZB.', 'FX_SCHEMA_MISMATCH');
        }

        $lines = preg_split('/\R/', $trimmed) ?: [];
        $headers = array_map(
            static fn (string $header): string => ltrim(trim($header), "\xEF\xBB\xBF"),
            str_getcsv((string) array_shift($lines), ',', '"', '\\'),
        );
        foreach (['TIME_PERIOD', 'OBS_VALUE', 'CURRENCY', 'CURRENCY_DENOM'] as $field) {
            if (!in_array($field, $headers, true)) {
                throw new ProviderException('Erforderliche Wechselkursfelder fehlen in der EZB-Antwort.', 'FX_SCHEMA_MISMATCH');
            }
        }

        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $utc = new DateTimeZone('UTC');
        $rates = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $row = array_combine($headers, array_pad($values, count($headers), null));
            if ($row === false || ($row['CURRENCY'] ?? '') !== 'USD' || ($row['CURRENCY_DENOM'] ?? '') !== 'EUR') {
                continue;
            }

            $dateText = (string) ($row['TIME_PERIOD'] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $utc);
            $rate = trim((string) ($row['OBS_VALUE'] ?? ''));
            if (
                $date === false
                || $date->format('Y-m-d') !== $dateText
                || $dateText < $fromDate
                || $dateText > $toDate
                || !is_numeric($rate)
                || (float) $rate <= 0
            ) {
                continue;
            }
            $rates[$dateText] = $rate;
        }

        if ($rates === []) {
            throw new ProviderException('Die EZB-Antwort enthält keine gültigen USD/EUR-Referenzkurse.', 'EMPTY_FX_RESPONSE');
        }
        ksort($rates);
        return $rates;
    }
}
