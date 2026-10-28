<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use DateTimeZone;

final class AnnualDividendAdjustment
{
    public const METHOD = 'annual_yield_december_31';

    public static function hasCoverage(
        array $rows,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): bool {
        $schedule = self::schedule($rows);
        for ($year = (int) $start->format('Y'); $year <= (int) $end->format('Y'); $year++) {
            $paymentDate = new DateTimeImmutable(sprintf('%d-12-31', $year), new DateTimeZone('UTC'));
            if ($paymentDate > $start && $paymentDate <= $end && !isset($schedule[$paymentDate->format('Y-m-d')])) {
                return false;
            }
        }
        return true;
    }

    public static function factor(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $rows,
    ): float {
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        if ($toDate <= $fromDate) {
            return 1.0;
        }

        $factor = 1.0;
        foreach (self::schedule($rows) as $paymentDate => $yieldPct) {
            if ($paymentDate > $fromDate && $paymentDate <= $toDate) {
                $factor *= 1.0 + ($yieldPct / 100.0);
            }
        }
        return $factor;
    }

    public static function metadata(array $rows, string $assetName = ''): array
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
            'applied' => true,
            'method' => self::METHOD,
            'sourceName' => 'Hinterlegte Jahresdividendenrenditen'
                . ($assetName !== '' ? ': ' . $assetName : ''),
            'sourceUrl' => null,
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
            $sourceDate = (string) ($row['source_observation_date'] ?? '');
            $year = substr((string) ($row['observation_month'] ?? ''), 0, 4);
            $paymentDate = preg_match('/^\d{4}-12-31$/', $sourceDate)
                ? $sourceDate
                : (preg_match('/^\d{4}$/', $year) ? $year . '-12-31' : '');
            $value = (float) ($row['annual_yield_pct'] ?? 0);
            if ($paymentDate !== '' && $value > 0.0 && $value <= 100.0) {
                $schedule[$paymentDate] = $value;
            }
        }
        ksort($schedule);
        return $schedule;
    }
}
