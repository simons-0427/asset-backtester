<?php

declare(strict_types=1);

namespace AssetBacktester\Contracts;

use DateTimeImmutable;

interface DividendYieldReaderInterface
{
    public function rangeWithStartAnchor(
        int $assetId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array;
}
