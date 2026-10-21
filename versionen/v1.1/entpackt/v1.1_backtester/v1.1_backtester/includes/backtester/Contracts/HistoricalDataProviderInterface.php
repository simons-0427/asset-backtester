<?php

declare(strict_types=1);

namespace AssetBacktester\Contracts;

use DateTimeImmutable;
use AssetBacktester\Domain\AssetDefinition;

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

