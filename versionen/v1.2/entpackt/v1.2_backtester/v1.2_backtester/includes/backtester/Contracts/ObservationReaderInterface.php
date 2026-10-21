<?php

declare(strict_types=1);

namespace Smstark\Backtester\Contracts;

use DateTimeImmutable;

interface ObservationReaderInterface
{
    public function seriesWithStartAnchor(int $assetId, DateTimeImmutable $start, DateTimeImmutable $end): array;

    public function valueAtOrBefore(int $assetId, DateTimeImmutable $date): ?array;
}

