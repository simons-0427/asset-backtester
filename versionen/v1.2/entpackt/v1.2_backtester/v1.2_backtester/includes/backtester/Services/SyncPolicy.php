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

    public static function nextAssetSuccessCheck(
        DateTimeImmutable $now,
        string $nativeFrequency,
        string $calendarType,
        array $config,
    ): DateTimeImmutable {
        $frequency = strtolower(trim($nativeFrequency));
        $calendar = strtolower(trim($calendarType));

        if ($frequency === 'monthly') {
            return self::startOfDueDay(
                $now,
                (int) ($config['monthly_check_interval_days'] ?? 7),
            );
        }

        if ($frequency === 'quarterly') {
            return self::startOfDueDay(
                $now,
                (int) ($config['quarterly_check_interval_days'] ?? 30),
            );
        }

        if ($frequency === 'daily') {
            $next = self::startOfDueDay($now, 1);
            if ($calendar !== 'market_weekdays') {
                return $next;
            }
            while ((int) $next->format('N') >= 6) {
                $next = $next->modify('+1 day');
            }
            return $next;
        }

        return self::nextSuccessCheck(
            $now,
            (int) ($config['success_check_delay_hours'] ?? 18),
        );
    }

    public static function nextFailureCheck(DateTimeImmutable $now, int $failures, int $maxHours): DateTimeImmutable
    {
        $hours = min(max(1, $maxHours), 2 ** min(10, max(0, $failures)));
        return $now->modify(sprintf('+%d hours', $hours));
    }

    public static function nextAssetFailureCheck(
        DateTimeImmutable $now,
        int $failures,
        string $nativeFrequency,
        array $config,
    ): DateTimeImmutable {
        $maxHours = (int) ($config['failure_backoff_max_hours'] ?? 48);
        if (strtolower(trim($nativeFrequency)) === 'daily') {
            $maxHours = min(
                $maxHours,
                (int) ($config['daily_failure_backoff_max_hours'] ?? 24),
            );
        }
        return self::nextFailureCheck($now, $failures, $maxHours);
    }

    public static function lockExpired(?string $lockUntil, DateTimeImmutable $now): bool
    {
        return $lockUntil === null || new DateTimeImmutable($lockUntil) <= $now;
    }

    private static function startOfDueDay(DateTimeImmutable $now, int $days): DateTimeImmutable
    {
        return $now->modify(sprintf('+%d days', max(1, $days)))->setTime(0, 0, 0);
    }
}
