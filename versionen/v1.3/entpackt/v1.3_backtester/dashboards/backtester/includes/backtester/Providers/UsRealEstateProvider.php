<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;

/**
 * Loads the official monthly FHFA US house-price index through the same
 * FRED API transport already used by the S&P 500 provider.
 */
final class UsRealEstateProvider implements HistoricalDataProviderInterface
{
    private readonly FredProvider $delegate;

    public function __construct(private readonly array $config)
    {
        $this->delegate = new FredProvider($config);
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return self::asIndexPoints($this->delegate->fetchRange($asset, $from, $to));
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        $utc = new DateTimeZone('UTC');
        $to = new DateTimeImmutable('today', $utc);
        $revisionDays = (int) ($this->config['real_estate_revision_lookback_days'] ?? 450);
        $from = $to->modify(sprintf('-%d days', max($lookbackDays, $revisionDays)));
        return $this->fetchRange($asset, $from, $to);
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->slug === 'us_real_estate'
            && $asset->providerType === 'fred'
            && $asset->providerSymbol === 'HPIPONM226S';
    }

    /**
     * The observation table uses eur_value as its normalized numeric column.
     * PTS is a dimensionless index unit, not a foreign currency, so it is
     * copied with factor 1 instead of applying an FX conversion.
     *
     * @param list<HistoricalObservation> $observations
     * @return list<HistoricalObservation>
     */
    public static function asIndexPoints(array $observations): array
    {
        return array_map(
            static fn (HistoricalObservation $row): HistoricalObservation => new HistoricalObservation(
                $row->date,
                $row->value,
                $row->currency,
                $row->sourceTimestamp,
                $row->qualityStatus,
                $row->sourceRevision,
                $row->rawPayloadHash,
                $row->value,
                '1',
                $row->date,
            ),
            $observations,
        );
    }
}
