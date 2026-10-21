<?php

declare(strict_types=1);

namespace AssetBacktester\Contracts;

interface BacktestAdjustmentInterface
{
    public function apply(array $series, array $context): array;
}

