<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\DividendYieldReaderInterface;
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

    public function __construct(
        private readonly ObservationReaderInterface $observations,
        private readonly ?DividendYieldReaderInterface $dividendYields = null,
        private readonly bool $applyDividendAdjustments = false,
    ) {
    }

    public function build(
        array $assets,
        DateTimeImmutable $globalEnd,
        float $amount = 10000.0,
        float $annualUsNetRentalCashflowPct = 0.0,
        float $annualDeNetRentalCashflowPct = 0.0,
    ): array
    {
        $rows = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof AssetDefinition) {
                continue;
            }
            $end = $this->observations->valueAtOrBefore($asset->id, $globalEnd);
            $dividendRows = [];
            $dividendMethod = self::dividendMethodForAsset($asset->slug);
            $requireDividendCoverage = (
                $dividendMethod !== null
                && $this->applyDividendAdjustments
                && $this->dividendYields !== null
            );
            if ($requireDividendCoverage) {
                $dividendRows = $this->dividendYields->rangeWithStartAnchor(
                    $asset->id,
                    $globalEnd->modify('-10 years'),
                    $globalEnd,
                );
            }
            $periods = [];
            foreach (self::PERIODS as $label => $modifier) {
                $targetStart = $globalEnd->modify($modifier);
                $periods[$label] = $this->calculatePeriod(
                    $asset,
                    $targetStart,
                    $globalEnd,
                    $end,
                    $amount,
                    $dividendRows,
                    $requireDividendCoverage,
                    $dividendMethod,
                    match ($asset->slug) {
                        'us_real_estate' => $annualUsNetRentalCashflowPct,
                        'de_real_estate' => $annualDeNetRentalCashflowPct,
                        default => 0.0,
                    },
                );
            }
            $rows[] = [
                'slug' => $asset->slug,
                'displayName' => $asset->displayName,
                'currency' => $asset->displayCurrency,
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
        float $amount,
        array $dividendRows = [],
        bool $requireDividendCoverage = false,
        ?string $dividendMethod = null,
        float $annualNetRentalCashflowPct = 0.0,
    ): ?array {
        if ($end === null || $asset->firstDate === null || $asset->firstDate > $targetStart->format('Y-m-d')) {
            return null;
        }
        $start = $this->observations->valueAtOrBefore($asset->id, $targetStart);
        if ($start === null || (float) $start['eur_value'] <= 0) {
            return null;
        }
        if ($requireDividendCoverage) {
            $hasCoverage = $dividendMethod === AnnualDividendAdjustment::METHOD
                ? AnnualDividendAdjustment::hasCoverage($dividendRows, $targetStart, $globalEnd)
                : DividendAdjustment::hasCoverage($dividendRows, $targetStart);
            if (!$hasCoverage) {
                return null;
            }
        }

        $startValue = (float) $start['eur_value'];
        $endValue = (float) $end['eur_value'];
        $dividendFactor = !$requireDividendCoverage
            ? 1.0
            : ($dividendMethod === AnnualDividendAdjustment::METHOD
                ? AnnualDividendAdjustment::factor(
                    $targetStart,
                    $globalEnd,
                    $dividendRows,
                )
                : DividendAdjustment::factor(
                    $targetStart,
                    $globalEnd,
                    $dividendRows,
                ));
        $growthFactor = ($endValue / $startValue) * $dividendFactor
            + RentalCashflowAdjustment::growthIncrement(
                $targetStart,
                $globalEnd,
                $annualNetRentalCashflowPct,
            );
        $returnPct = ($growthFactor - 1.0) * 100.0;
        return [
            'targetStartDate' => $targetStart->format('Y-m-d'),
            'globalEndDate' => $globalEnd->format('Y-m-d'),
            'effectiveStartDate' => $start['observation_date'],
            'effectiveEndDate' => $end['observation_date'],
            'startValue' => round($startValue, 10),
            'endValue' => round($endValue, 10),
            'returnPct' => round($returnPct, 4),
            'hypotheticalValue' => round($amount * $growthFactor, 2),
            'dividendAdjustmentApplied' => $requireDividendCoverage,
            'rentalCashflowAdjustmentApplied' => $annualNetRentalCashflowPct > 0.0,
            'annualNetRentalCashflowPct' => round($annualNetRentalCashflowPct, 4),
        ];
    }

    private static function dividendMethodForAsset(string $slug): ?string
    {
        return match ($slug) {
            'sp500' => DividendAdjustment::METHOD,
            'msci_world', 'dax', 'eurostoxx50' => AnnualDividendAdjustment::METHOD,
            default => null,
        };
    }
}
