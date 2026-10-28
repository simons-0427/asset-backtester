<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Domain\DividendYieldObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class MultplDividendYieldProvider
{
    public const DEFAULT_URL = 'https://www.multpl.com/s-p-500-dividend-yield/table/by-month';

    private readonly HttpClient $http;

    public function __construct(private readonly array $config = [])
    {
        $this->http = new HttpClient(
            (int) ($config['timeout_seconds'] ?? 20),
            (int) ($config['max_retries'] ?? 2),
            (int) ($config['retry_base_ms'] ?? 750),
        );
    }

    /** @return DividendYieldObservation[] */
    public function fetchAll(): array
    {
        $url = (string) ($this->config['url'] ?? self::DEFAULT_URL);
        if (!str_starts_with($url, 'https://www.multpl.com/')) {
            throw new ProviderException('Die Multpl-Quell-URL ist nicht erlaubt.', 'PROVIDER_CONFIG_INVALID');
        }

        $html = $this->http->get($url, [
            'Accept: text/html,application/xhtml+xml;q=0.9',
            'Accept-Language: en-US,en;q=0.8',
        ]);
        return self::parseHtml($html, $url);
    }

    /** @return DividendYieldObservation[] */
    public static function parseHtml(string $html, string $sourceUrl = self::DEFAULT_URL): array
    {
        if (!preg_match(
            '~<table\b[^>]*\bid=["\']datatable["\'][^>]*>(.*?)</table>~is',
            $html,
            $tableMatch,
        )) {
            throw new ProviderException(
                'Die Multpl-Dividendentabelle wurde nicht gefunden.',
                'PROVIDER_RESPONSE_INVALID',
            );
        }

        preg_match_all('~<tr\b[^>]*>(.*?)</tr>~is', $tableMatch[1], $rowMatches);
        $byMonth = [];
        foreach ($rowMatches[1] ?? [] as $rowHtml) {
            preg_match_all('~<td\b[^>]*>(.*?)</td>~is', $rowHtml, $cellMatches);
            if (count($cellMatches[1] ?? []) !== 2) {
                continue;
            }

            $dateText = self::cellText($cellMatches[1][0]);
            $valueText = self::cellText($cellMatches[1][1]);
            $date = DateTimeImmutable::createFromFormat(
                '!M j, Y',
                $dateText,
                new DateTimeZone('UTC'),
            );
            if ($date === false || !preg_match('/(-?\d+(?:[.,]\d+)?)\s*%/', $valueText, $valueMatch)) {
                throw new ProviderException(
                    'Eine Zeile der Multpl-Dividendentabelle konnte nicht gelesen werden.',
                    'PROVIDER_RESPONSE_INVALID',
                );
            }

            $value = (float) str_replace(',', '.', $valueMatch[1]);
            if (!is_finite($value) || $value <= 0.0 || $value > 100.0) {
                throw new ProviderException(
                    'Multpl hat eine unplausible Dividendenrendite geliefert.',
                    'PROVIDER_RESPONSE_INVALID',
                );
            }

            $normalizedValue = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
            $isEstimate = stripos($cellMatches[1][1], 'title="Estimate"') !== false
                || stripos($cellMatches[1][1], "title='Estimate'") !== false
                || str_contains($valueText, '†');
            $month = $date->format('Y-m-01');
            $candidate = new DividendYieldObservation(
                $date,
                $normalizedValue,
                $isEstimate,
                $sourceUrl,
                hash('sha256', $dateText . '|' . $normalizedValue . '|' . ($isEstimate ? '1' : '0')),
            );

            if (
                !isset($byMonth[$month])
                || $candidate->sourceObservationDate > $byMonth[$month]->sourceObservationDate
            ) {
                $byMonth[$month] = $candidate;
            }
        }

        if (count($byMonth) < 2) {
            throw new ProviderException(
                'Die Multpl-Dividendentabelle enthielt zu wenige verwertbare Monatswerte.',
                'PROVIDER_RESPONSE_INVALID',
            );
        }

        ksort($byMonth);
        return array_values($byMonth);
    }

    private static function cellText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
