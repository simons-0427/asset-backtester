<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Exceptions\ProviderException;

final class ProviderRegistry
{
    public function __construct(private readonly array $providers)
    {
    }

    public function forAsset(AssetDefinition $asset): HistoricalDataProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof HistoricalDataProviderInterface && $provider->supports($asset)) {
                return $provider;
            }
        }
        throw new ProviderException('Kein Provider für ' . $asset->displayName . ' konfiguriert.', 'PROVIDER_NOT_FOUND');
    }
}

