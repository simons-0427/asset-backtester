<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;

final class GoldProvider implements HistoricalDataProviderInterface
{
    private readonly AlphaVantageProvider $delegate;
    private readonly UsdToEurConverter $converter;

    public function __construct(array $alphaVantageConfig, array $ecbConfig = [])
    {
        $this->delegate = new AlphaVantageProvider($alphaVantageConfig);
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
        return $asset->slug === 'gold' && $asset->providerType === 'alpha_vantage';
    }
}
