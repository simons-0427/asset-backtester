<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\ObservationReaderInterface;
use Smstark\Backtester\Domain\AssetDefinition;

final class SummaryService
{
    public const PERIODS = [
        '1W' => '-1 week',
        '1M' => '-1 month',
        '6M' => '-6 months',
        '1Y' => '-1 year',
        '3Y' => '-3 years',
        '5Y' => '-5 years',
        '10Y' => '-10 years',
    ];

    public function __construct(private readonly ObservationReaderInterface $observations)
    {
    }

    public function build(array $assets, DateTimeImmutable $globalEnd, float $amount = 10000.0): array
    {
        $rows = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof AssetDefinition) {
                continue;
            }
            $end = $this->observations->valueAtOrBefore($asset->id, $globalEnd);
            $periods = [];
            foreach (self::PERIODS as $label => $modifier) {
                $targetStart = $globalEnd->modify($modifier);
                $periods[$label] = $this->calculatePeriod($asset, $targetStart, $globalEnd, $end, $amount);
            }
            $rows[] = [
                'slug' => $asset->slug,
                'displayName' => $asset->displayName,
                'lastRawObservationDate' => $end['observation_date'] ?? null,
                'periods' => $periods,
            ];
        }

        return [
            'globalEndDate' => $globalEnd->format('Y-m-d'),
            'amount' => $amount,
            'periodLabels' => array_keys(self::PERIODS),
            'rows' => $rows,
        ];
    }

    private function calculatePeriod(
        AssetDefinition $asset,
        DateTimeImmutable $targetStart,
        DateTimeImmutable $globalEnd,
        ?array $end,
        float $amount
    ): ?array {
        if ($end === null || $asset->firstDate === null || $asset->firstDate > $targetStart->format('Y-m-d')) {
            return null;
        }
        $start = $this->observations->valueAtOrBefore($asset->id, $targetStart);
        if ($start === null || (float) $start['eur_value'] <= 0) {
            return null;
        }

        $startValue = (float) $start['eur_value'];
        $endValue = (float) $end['eur_value'];
        $returnPct = ($endValue / $startValue - 1.0) * 100.0;
        return [
            'targetStartDate' => $targetStart->format('Y-m-d'),
            'globalEndDate' => $globalEnd->format('Y-m-d'),
            'effectiveStartDate' => $start['observation_date'],
            'effectiveEndDate' => $end['observation_date'],
            'startValue' => round($startValue, 10),
            'endValue' => round($endValue, 10),
            'returnPct' => round($returnPct, 4),
            'hypotheticalValue' => round($amount * $endValue / $startValue, 2),
        ];
    }
}
