<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;

final class Sp500Provider implements HistoricalDataProviderInterface
{
    private readonly FredProvider $delegate;
    private readonly UsdToEurConverter $converter;

    public function __construct(array $fredConfig, array $ecbConfig = [])
    {
        $this->delegate = new FredProvider($fredConfig);
        $this->converter = new UsdToEurConverter(new EcbExchangeRateProvider($ecbConfig));
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->converter->convert($this->delegate->fetchRange($asset, $from, $to));
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        return $this->converter->convert($this->delegate->fetchRecent($asset, $lookbackDays));
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->slug === 'sp500' && $asset->providerType === 'fred';
    }
}
