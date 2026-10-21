<?php

declare(strict_types=1);

namespace Smstark\Backtester\Tests;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\ObservationReaderInterface;
use Smstark\Backtester\Domain\AssetDefinition;

final class ArrayObservationReader implements ObservationReaderInterface
{
    public function __construct(private readonly array $rowsByAsset)
    {
    }

    public function seriesWithStartAnchor(int $assetId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->rowsByAsset[$assetId] ?? [];
        $anchor = $this->valueAtOrBefore($assetId, $start);
        $selected = array_values(array_filter($rows, static fn (array $row): bool =>
            $row['observation_date'] >= $start->format('Y-m-d') && $row['observation_date'] <= $end->format('Y-m-d')
        ));
        if ($anchor && $anchor['observation_date'] < $start->format('Y-m-d')) {
            $selected[] = $anchor;
        }
        return $selected;
    }

    public function valueAtOrBefore(int $assetId, DateTimeImmutable $date): ?array
    {
        $candidate = null;
        foreach ($this->rowsByAsset[$assetId] ?? [] as $row) {
            if ($row['observation_date'] <= $date->format('Y-m-d')
                && ($candidate === null || $row['observation_date'] > $candidate['observation_date'])) {
                $candidate = $row;
            }
        }
        return $candidate;
    }
}

function assetFixture(
    int $id = 1,
    string $slug = 'sp500',
    string $providerType = 'alpha_vantage',
    ?string $firstDate = '2010-01-01',
    ?string $providerSymbol = null,
    ?string $providerDataset = null,
    string $rawCurrency = 'EUR',
    ?string $valueType = null,
): AssetDefinition {
    return new AssetDefinition(
        $id,
        1,
        $slug,
        $slug === 'bitcoin' ? 'Bitcoin' : ($slug === 'gold' ? 'Gold' : 'S&P 500'),
        strtoupper($slug),
        $slug === 'bitcoin' ? 'crypto' : 'equity',
        'Global',
        $providerSymbol ?? ($slug === 'bitcoin' ? 'bitcoin' : 'TEST.DEX'),
        $providerDataset ?? ($slug === 'bitcoin' ? 'market_chart_range' : 'TIME_SERIES_DAILY'),
        null,
        null,
        $rawCurrency,
        'EUR',
        $valueType ?? ($slug === 'bitcoin' ? 'spot_reference' : 'close'),
        'daily',
        $slug === 'bitcoin' ? 'calendar_daily' : 'market_weekdays',
        $slug === 'bitcoin' ? 'UTC' : 'Europe/Berlin',
        'Test-Proxy',
        'Testmethodik',
        null,
        $id * 10,
        true,
        $slug === 'bitcoin' ? 'coingecko-bitcoin' : ($providerType === 'fred' ? 'fred-sp500-live' : 'alpha-vantage-market'),
        $slug === 'bitcoin' ? 'CoinGecko' : ($providerType === 'fred' ? 'FRED' : 'Alpha Vantage'),
        $providerType,
        'https://example.invalid',
        null,
        null,
        $firstDate,
        '2026-08-01',
        '2026-08-02 06:00:00',
    );
}
