<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use DateTimeZone;

final class DividendAdjustment
{
    public const METHOD = 'multpl_monthly_yield_accrual';

    public static function hasCoverage(array $rows, DateTimeImmutable $start): bool
    {
        $schedule = self::schedule($rows);
        if ($schedule === []) {
            return false;
        }
        $startMonth = $start->format('Y-m');
        foreach (array_keys($schedule) as $month) {
            if ($month <= $startMonth) {
                return true;
            }
        }
        return false;
    }

    /**
     * Converts a monthly annualized yield schedule into a time-proportional
     * compounding factor. The monthly value is treated as an annual effective
     * rate and accrued over actual calendar days.
     */
    public static function factor(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $rows,
    ): float {
        $from = new DateTimeImmutable($from->format('Y-m-d'), new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($to->format('Y-m-d'), new DateTimeZone('UTC'));
        if ($to <= $from) {
            return 1.0;
        }

        $schedule = self::schedule($rows);
        if ($schedule === []) {
            return 1.0;
        }

        $factor = 1.0;
        $cursor = $from;
        while ($cursor < $to) {
            $nextMonth = $cursor->modify('first day of next month')->setTime(0, 0);
            $segmentEnd = $nextMonth < $to ? $nextMonth : $to;
            $days = (int) $cursor->diff($segmentEnd)->format('%a');
            $yieldPct = self::yieldAtOrBefore($schedule, $cursor->format('Y-m'));
            if ($yieldPct !== null && $days > 0) {
                $factor *= pow(1.0 + ($yieldPct / 100.0), $days / 365.2425);
            }
            $cursor = $segmentEnd;
        }
        return $factor;
    }

    public static function metadata(array $rows): array
    {
        $latestMonth = null;
        $latestSourceDate = null;
        $containsEstimate = false;
        foreach ($rows as $row) {
            $month = (string) ($row['observation_month'] ?? '');
            $sourceDate = (string) ($row['source_observation_date'] ?? '');
            if ($month !== '' && ($latestMonth === null || $month > $latestMonth)) {
                $latestMonth = $month;
            }
            if ($sourceDate !== '' && ($latestSourceDate === null || $sourceDate > $latestSourceDate)) {
                $latestSourceDate = $sourceDate;
            }
            $containsEstimate = $containsEstimate || (bool) ($row['is_estimate'] ?? false);
        }

        return [
            'applied' => $rows !== [],
            'method' => self::METHOD,
            'sourceName' => 'Multpl',
            'sourceUrl' => 'https://www.multpl.com/s-p-500-dividend-yield/table/by-month',
            'latestObservationMonth' => $latestMonth,
            'latestSourceObservationDate' => $latestSourceDate,
            'containsEstimate' => $containsEstimate,
        ];
    }

    /** @return array<string, float> */
    private static function schedule(array $rows): array
    {
        $schedule = [];
        foreach ($rows as $row) {
            $month = substr((string) ($row['observation_month'] ?? ''), 0, 7);
            $value = (float) ($row['annual_yield_pct'] ?? 0);
            if (preg_match('/^\d{4}-\d{2}$/', $month) && $value > 0.0 && $value <= 100.0) {
                $schedule[$month] = $value;
            }
        }
        ksort($schedule);
        return $schedule;
    }

    private static function yieldAtOrBefore(array $schedule, string $month): ?float
    {
        $candidate = null;
        foreach ($schedule as $scheduleMonth => $value) {
            if ($scheduleMonth > $month) {
                break;
            }
            $candidate = (float) $value;
        }
        return $candidate;
    }
}
