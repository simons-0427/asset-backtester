<?php

declare(strict_types=1);

namespace Smstark\Backtester\Contracts;

use DateTimeImmutable;
use Smstark\Backtester\Domain\AssetDefinition;

interface HistoricalDataProviderInterface
{
    public function fetchRange(
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array;

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array;

    public function supports(AssetDefinition $asset): bool;
}

