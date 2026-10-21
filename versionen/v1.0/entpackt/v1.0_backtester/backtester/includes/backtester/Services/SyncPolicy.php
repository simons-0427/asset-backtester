<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;

final class SyncPolicy
{
    public static function nextSuccessCheck(DateTimeImmutable $now, int $hours): DateTimeImmutable
    {
        return $now->modify(sprintf('+%d hours', max(1, $hours)));
    }

    public static function nextFailureCheck(DateTimeImmutable $now, int $failures, int $maxHours): DateTimeImmutable
    {
        $hours = min(max(1, $maxHours), 2 ** min(10, max(0, $failures)));
        return $now->modify(sprintf('+%d hours', $hours));
    }

    public static function lockExpired(?string $lockUntil, DateTimeImmutable $now): bool
    {
        return $lockUntil === null || new DateTimeImmutable($lockUntil) <= $now;
    }
}

