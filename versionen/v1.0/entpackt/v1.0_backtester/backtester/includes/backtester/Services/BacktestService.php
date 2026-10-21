<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\ObservationReaderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Exceptions\AssetUnavailableException;

final class BacktestService
{
    public function __construct(private readonly ObservationReaderInterface $observations)
    {
    }

    public function run(array $assets, DateTimeImmutable $start, DateTimeImmutable $end, float $amount): array
    {
        $series = [];
        $warnings = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof AssetDefinition) {
                continue;
            }
            $rows = $this->observations->seriesWithStartAnchor($asset->id, $start, $end);
            try {
                $annualizationPeriods = $asset->calendarType === 'calendar_daily' ? 365.2425 : 252.0;
                $calculated = self::calculateSeries($rows, $start, $end, $amount, $annualizationPeriods);
                $calculated['slug'] = $asset->slug;
                $calculated['displayName'] = $asset->displayName;
                $calculated['nativeFrequency'] = $asset->nativeFrequency;
                $calculated['currency'] = $asset->displayCurrency;
                $calculated['source'] = $asset->toPublicArray()['source'];
                $series[] = $calculated;
            } catch (AssetUnavailableException $error) {
                $warnings[] = [
                    'code' => 'ASSET_UNAVAILABLE_AT_START',
                    'asset' => $asset->slug,
                    'message' => $error->getMessage(),
                    'firstAvailableDate' => $asset->firstDate,
                ];
            }
        }

        return [
            'commonCalendar' => self::calendar($start, $end),
            'series' => $series,
            'warnings' => $warnings,
        ];
    }

    public static function calculateSeries(
        array $rows,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        float $amount,
        float $annualizationPeriods = 252.0,
    ): array {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['observation_date'], (string) $b['observation_date']));
        $byDate = [];
        foreach ($rows as $row) {
            $date = (string) ($row['observation_date'] ?? '');
            $value = (float) ($row['eur_value'] ?? 0);
            if ($date !== '' && $value > 0) {
                $byDate[$date] = [
                    'value' => $value,
                    'qualityStatus' => (string) ($row['quality_status'] ?? 'valid'),
                    'sourceRevision' => $row['source_revision'] ?? null,
                ];
            }
        }
        ksort($byDate);

        $currentValue = null;
        $lastRawDate = null;
        foreach ($byDate as $date => $row) {
            if ($date <= $start->format('Y-m-d')) {
                $currentValue = $row['value'];
                $lastRawDate = $date;
            }
        }
        if ($currentValue === null || $lastRawDate === null) {
            throw new AssetUnavailableException('Für dieses Asset existiert am gewählten Startdatum noch kein Wert.');
        }

        $startValue = $currentValue;
        $startObservationDate = $lastRawDate;
        $points = [];
        $runningPeak = $amount;
        $maxDrawdown = 0.0;

        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $isRaw = isset($byDate[$date]);
            if ($isRaw) {
                $currentValue = $byDate[$date]['value'];
                $lastRawDate = $date;
            }
            $portfolioValue = $amount * $currentValue / $startValue;
            $returnPct = ($currentValue / $startValue - 1.0) * 100.0;
            $runningPeak = max($runningPeak, $portfolioValue);
            $drawdown = $portfolioValue / $runningPeak - 1.0;
            $maxDrawdown = min($maxDrawdown, $drawdown);

            $points[] = [
                'date' => $date,
                'portfolioValue' => round($portfolioValue, 4),
                'returnPct' => round($returnPct, 6),
                'sourceValue' => round($currentValue, 10),
                'isCarriedForward' => !$isRaw,
                'lastRawObservationDate' => $lastRawDate,
                'qualityStatus' => $isRaw ? $byDate[$date]['qualityStatus'] : 'carried_forward',
            ];
        }

        $lastPoint = end($points);
        $finalValue = (float) $lastPoint['portfolioValue'];
        $days = max(1, (int) $start->diff($end)->format('%a'));
        $years = $days / 365.2425;
        $cagrPct = (pow($finalValue / $amount, 1 / $years) - 1.0) * 100.0;
        $maxDrawdownPct = $maxDrawdown * 100.0;
        $observedReturns = self::observedReturns($byDate, $start, $end, $startValue);
        $riskMetrics = self::riskMetrics(
            $observedReturns,
            $annualizationPeriods,
            $cagrPct,
            $maxDrawdownPct,
        );

        return [
            'effectiveStartDate' => $start->format('Y-m-d'),
            'effectiveEndDate' => $end->format('Y-m-d'),
            'startObservationDate' => $startObservationDate,
            'lastRawObservationDate' => $lastRawDate,
            'points' => $points,
            'metrics' => [
                'finalValue' => round($finalValue, 2),
                'absoluteReturn' => round($finalValue - $amount, 2),
                'totalReturnPct' => round(($finalValue / $amount - 1.0) * 100.0, 4),
                'cagrPct' => round($cagrPct, 4),
                'maxDrawdownPct' => round($maxDrawdownPct, 4),
                'effectiveStartDate' => $start->format('Y-m-d'),
                'effectiveEndDate' => $end->format('Y-m-d'),
                'lastRawObservationDate' => $lastRawDate,
            ] + $riskMetrics,
        ];
    }

    /**
     * Berechnet Renditen nur zwischen echten Beobachtungen. Fortgeschriebene
     * Kalenderwerte würden die Volatilität sonst künstlich absenken.
     *
     * @return array<int, array{date: string, return: float}>
     */
    private static function observedReturns(
        array $byDate,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        float $startValue,
    ): array {
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        $previousValue = $startValue;
        $returns = [];

        foreach ($byDate as $date => $row) {
            if ($date <= $startDate || $date > $endDate) {
                continue;
            }
            $value = (float) $row['value'];
            if ($previousValue <= 0 || $value <= 0) {
                continue;
            }
            $returns[] = [
                'date' => $date,
                'return' => $value / $previousValue - 1.0,
            ];
            $previousValue = $value;
        }

        return $returns;
    }

    /**
     * @param array<int, array{date: string, return: float}> $observedReturns
     */
    private static function riskMetrics(
        array $observedReturns,
        float $annualizationPeriods,
        float $cagrPct,
        float $maxDrawdownPct,
    ): array {
        $count = count($observedReturns);
        $annualizationPeriods = $annualizationPeriods > 0 ? $annualizationPeriods : 252.0;
        $best = null;
        $worst = null;
        $positive = 0;
        $sum = 0.0;

        foreach ($observedReturns as $observation) {
            $return = $observation['return'];
            $sum += $return;
            if ($return > 0) {
                $positive++;
            }
            if ($best === null || $return > $best['return']) {
                $best = $observation;
            }
            if ($worst === null || $return < $worst['return']) {
                $worst = $observation;
            }
        }

        $volatilityPct = null;
        $sharpeRatio = null;
        if ($count >= 2) {
            $mean = $sum / $count;
            $squaredDifferences = 0.0;
            foreach ($observedReturns as $observation) {
                $squaredDifferences += ($observation['return'] - $mean) ** 2;
            }
            $standardDeviation = sqrt($squaredDifferences / ($count - 1));
            $volatilityPct = $standardDeviation * sqrt($annualizationPeriods) * 100.0;
            if ($standardDeviation > 0) {
                $sharpeRatio = ($mean / $standardDeviation) * sqrt($annualizationPeriods);
            }
        }

        $calmarRatio = abs($maxDrawdownPct) > 0.0000001
            ? $cagrPct / abs($maxDrawdownPct)
            : null;

        return [
            'annualizedVolatilityPct' => $volatilityPct === null ? null : round($volatilityPct, 4),
            'annualizationPeriods' => round($annualizationPeriods, 4),
            'sharpeRatio' => $sharpeRatio === null ? null : round($sharpeRatio, 4),
            'calmarRatio' => $calmarRatio === null ? null : round($calmarRatio, 4),
            'bestDayPct' => $best === null ? null : round($best['return'] * 100.0, 4),
            'bestDayDate' => $best['date'] ?? null,
            'worstDayPct' => $worst === null ? null : round($worst['return'] * 100.0, 4),
            'worstDayDate' => $worst['date'] ?? null,
            'positiveDaysPct' => $count === 0 ? null : round(($positive / $count) * 100.0, 4),
            'observedReturnCount' => $count,
        ];
    }

    public static function calendar(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $calendar = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $calendar[] = $day->format('Y-m-d');
        }
        return $calendar;
    }
}
