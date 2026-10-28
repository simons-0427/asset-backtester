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
 * Fetches only the recent MSCI World USD price-index values exposed for the
 * exact Yahoo symbol. It is deliberately not a historical backfill provider.
 */
final class MsciWorldProvider implements HistoricalDataProviderInterface
{
    private readonly HttpClient $http;
    private readonly UsdToEurConverter $converter;

    public function __construct(private readonly array $config = [], array $ecbConfig = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 2),
            (int) ($config['retry_base_ms'] ?? 750),
        );
        $this->converter = new UsdToEurConverter(new EcbExchangeRateProvider($ecbConfig));
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!(bool) ($this->config['enabled'] ?? false)) {
            throw new ProviderException('Der MSCI-World-Abruf ist bis zur Klärung der Nutzungsrechte deaktiviert.', 'PROVIDER_NOT_CONFIGURED');
        }
        self::assertAsset($asset);
        $template = (string) ($this->config['url_template']
            ?? 'https://query1.finance.yahoo.com/v8/finance/chart/%s');
        $url = sprintf($template, rawurlencode($asset->providerSymbol));
        $query = http_build_query([
            'range' => '1mo',
            'interval' => '1d',
            'events' => 'history',
        ], '', '&', PHP_QUERY_RFC3986);
        $raw = self::parseJson($this->http->get(rtrim($url, '?') . '?' . $query), $asset, $from, $to);
        return $this->converter->convert($raw);
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
        return $asset->slug === 'msci_world' && $asset->providerType === 'yahoo_chart_scraper';
    }

    public static function parseJson(
        string $json,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        self::assertAsset($asset);
        $payload = json_decode($json, true);
        $result = $payload['chart']['result'][0] ?? null;
        if (!is_array($result) || !is_array($result['timestamp'] ?? null)) {
            throw new ProviderException('Unerwartetes Antwortformat für den MSCI World.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $closes = $result['indicators']['quote'][0]['close'] ?? null;
        if (!is_array($closes)) {
            throw new ProviderException('In der MSCI-World-Antwort fehlen Schlusswerte.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $returnedSymbol = (string) ($result['meta']['symbol'] ?? '');
        if ($returnedSymbol !== $asset->providerSymbol) {
            throw new ProviderException('Der MSCI-World-Abruf lieferte ein anderes Symbol.', 'SYMBOL_MISMATCH');
        }

        $timezoneName = (string) ($result['meta']['exchangeTimezoneName'] ?? 'America/New_York');
        try {
            $marketTimezone = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new ProviderException('Ungültige Zeitzone in der MSCI-World-Antwort.', 'PROVIDER_SCHEMA_MISMATCH');
        }
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $today = (new DateTimeImmutable('today', $marketTimezone))->format('Y-m-d');
        $observations = [];
        foreach ($result['timestamp'] as $index => $timestamp) {
            $value = $closes[$index] ?? null;
            if (!is_numeric($timestamp) || !is_numeric($value) || (float) $value <= 0) {
                continue;
            }
            $date = (new DateTimeImmutable('@' . (string) $timestamp))->setTimezone($marketTimezone)->setTime(0, 0);
            $dateText = $date->format('Y-m-d');
            if ($dateText < $fromDate || $dateText > $toDate) {
                continue;
            }
            $decimal = self::decimal((float) $value, 6);
            $rowPayload = $dateText . '|' . $decimal . '|' . $returnedSymbol;
            $observations[] = new HistoricalObservation(
                $date,
                $decimal,
                'USD',
                null,
                $dateText === $today ? 'provisional' : 'valid',
                substr('yahoo-chart:' . $returnedSymbol . ':' . $dateText, 0, 100),
                hash('sha256', $rowPayload),
            );
        }
        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    private static function assertAsset(AssetDefinition $asset): void
    {
        if ($asset->providerSymbol !== '^990100-USD-STRD') {
            throw new ProviderException('Für den MSCI World muss der USD-Preisindex ^990100-USD-STRD konfiguriert sein.', 'SYMBOL_NOT_CONFIGURED');
        }
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
