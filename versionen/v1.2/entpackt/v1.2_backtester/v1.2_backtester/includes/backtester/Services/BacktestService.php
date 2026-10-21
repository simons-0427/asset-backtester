<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\DividendYieldReaderInterface;
use Smstark\Backtester\Contracts\ObservationReaderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Exceptions\AssetUnavailableException;

final class BacktestService
{
    public function __construct(
        private readonly ObservationReaderInterface $observations,
        private readonly ?DividendYieldReaderInterface $dividendYields = null,
        private readonly bool $applyDividendAdjustments = false,
    ) {
    }

    public function run(
        array $assets,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        float $amount,
        float $annualUsNetRentalCashflowPct = 0.0,
        float $annualDeNetRentalCashflowPct = 0.0,
        ?AssetDefinition $inflationAsset = null,
        ?float $customInflationPct = null,
        float $annualTerPct = 0.0,
        array $taxSettings = [],
    ): array
    {
        $series = [];
        $warnings = [];
        $inflationRows = $inflationAsset === null
            ? []
            : $this->observations->seriesWithStartAnchor($inflationAsset->id, $start, $end);
        foreach ($assets as $asset) {
            if (!$asset instanceof AssetDefinition) {
                continue;
            }
            $rows = $this->observations->seriesWithStartAnchor($asset->id, $start, $end);
            try {
                $dividendRows = [];
                $dividendMethod = self::dividendMethodForAsset($asset->slug);
                $applyDividends = $dividendMethod !== null
                    && $this->applyDividendAdjustments
                    && $this->dividendYields !== null;
                if ($applyDividends) {
                    $dividendRows = $this->dividendYields->rangeWithStartAnchor($asset->id, $start, $end);
                    $hasCoverage = $dividendMethod === AnnualDividendAdjustment::METHOD
                        ? AnnualDividendAdjustment::hasCoverage($dividendRows, $start, $end)
                        : DividendAdjustment::hasCoverage($dividendRows, $start);
                    if (!$hasCoverage) {
                        throw new AssetUnavailableException(
                            'Für den gewählten Zeitraum fehlen Dividendenwerte.'
                        );
                    }
                }
                $rentalCashflowPct = match ($asset->slug) {
                    'us_real_estate' => $annualUsNetRentalCashflowPct,
                    'de_real_estate' => $annualDeNetRentalCashflowPct,
                    default => 0.0,
                };
                $applyRentalCashflow = in_array($asset->slug, ['us_real_estate', 'de_real_estate'], true);
                $assetTerPct = $annualTerPct;
                $annualizationPeriods = self::annualizationPeriods($asset);
                $calculated = self::calculateSeries(
                    $rows,
                    $start,
                    $end,
                    $amount,
                    $annualizationPeriods,
                    $dividendRows,
                    $dividendMethod ?? DividendAdjustment::METHOD,
                    $rentalCashflowPct,
                    $inflationRows,
                    $customInflationPct,
                    $assetTerPct,
                    $asset->slug,
                    $taxSettings,
                );
                $calculated['slug'] = $asset->slug;
                $calculated['displayName'] = $asset->displayName;
                $calculated['nativeFrequency'] = $asset->nativeFrequency;
                $calculated['currency'] = $asset->displayCurrency;
                $calculated['source'] = $asset->toPublicArray()['source'];
                $calculated['dividendAdjustment'] = $applyDividends
                    ? ($dividendMethod === AnnualDividendAdjustment::METHOD
                        ? AnnualDividendAdjustment::metadata($dividendRows, $asset->displayName)
                        : DividendAdjustment::metadata($dividendRows))
                    : ['applied' => false];
                $calculated['rentalCashflowAdjustment'] = RentalCashflowAdjustment::metadata(
                    $rentalCashflowPct,
                    $applyRentalCashflow && $rentalCashflowPct > 0.0,
                );
                $calculated['inflationAdjustment'] = $inflationAsset !== null
                    ? [
                        'applied' => true,
                        'type' => 'historical_index',
                        'slug' => $inflationAsset->slug,
                        'displayName' => $inflationAsset->displayName,
                        'lastRawObservationDate' => $calculated['inflationAdjustment']['lastRawObservationDate'] ?? null,
                    ]
                    : ($customInflationPct !== null
                        ? [
                            'applied' => true,
                            'type' => 'custom_rate',
                            'displayName' => 'Eigene Inflation',
                            'annualRatePct' => round($customInflationPct, 4),
                            'lastRawObservationDate' => null,
                        ]
                        : ['applied' => false]);
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
        array $annualDividendYields = [],
        string $dividendMethod = DividendAdjustment::METHOD,
        float $annualNetRentalCashflowPct = 0.0,
        array $inflationRows = [],
        ?float $customInflationPct = null,
        float $annualTerPct = 0.0,
        string $taxAssetSlug = '',
        array $taxSettings = [],
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

        $inflationByDate = [];
        foreach ($inflationRows as $row) {
            $date = (string) ($row['observation_date'] ?? '');
            $value = (float) ($row['eur_value'] ?? 0);
            if ($date !== '' && $value > 0) {
                $inflationByDate[$date] = $value;
            }
        }
        ksort($inflationByDate);

        $inflationStartValue = null;
        $currentInflationValue = null;
        $lastInflationRawDate = null;
        foreach ($inflationByDate as $date => $value) {
            if ($date <= $start->format('Y-m-d')) {
                $inflationStartValue = $value;
                $currentInflationValue = $value;
                $lastInflationRawDate = $date;
            }
        }
        if ($inflationRows !== [] && ($inflationStartValue === null || $currentInflationValue === null)) {
            throw new AssetUnavailableException('Für die gewählte Inflationsbereinigung existiert am Startdatum noch kein Wert.');
        }
        if ($customInflationPct !== null && 1.0 + $customInflationPct / 100.0 <= 0.0) {
            throw new AssetUnavailableException('Die eigene Inflationsrate kann nicht berechnet werden.');
        }
        $inflationApplied = $inflationRows !== [] || $customInflationPct !== null;

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
        $adjustedAtLastRaw = $startValue;
        $previousRawValue = $startValue;
        $previousReturnDate = $start;
        $previousObservedAdjustedValue = $startValue;
        $observedReturns = [];
        $startDate = $start->format('Y-m-d');

        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $isRaw = isset($byDate[$date]);
            if ($isRaw) {
                $currentValue = $byDate[$date]['value'];
                $lastRawDate = $date;
                if ($date > $startDate && $previousRawValue > 0.0) {
                    $priceFactor = $currentValue / $previousRawValue;
                    $dividendFactor = self::dividendFactor(
                        $previousReturnDate, $day, $annualDividendYields, $dividendMethod
                    );
                    $adjustedAtLastRaw *= $priceFactor * $dividendFactor;
                    $previousRawValue = $currentValue;
                    $previousReturnDate = $day;
                } elseif ($date === $startDate) {
                    $adjustedAtLastRaw = $currentValue;
                    $previousRawValue = $currentValue;
                    $previousReturnDate = $day;
                    $previousObservedAdjustedValue = $startValue;
                }
            }
            $adjustedValue = $adjustedAtLastRaw;
            if (!$isRaw && $day > $previousReturnDate) {
                $adjustedValue *= self::dividendFactor(
                    $previousReturnDate, $day, $annualDividendYields, $dividendMethod
                );
            }
            $rentalCashflowIndexValue = RentalCashflowAdjustment::indexIncrement(
                $start,
                $day,
                $startValue,
                $annualNetRentalCashflowPct,
            );
            $totalAdjustedValue = $adjustedValue + $rentalCashflowIndexValue;
            $terFactor = TerAdjustment::factor($start, $day, $annualTerPct);
            $terAdjustedValue = $totalAdjustedValue * $terFactor;
            $inflationFactor = 1.0;
            if ($inflationByDate !== []) {
                if (isset($inflationByDate[$date])) {
                    $currentInflationValue = $inflationByDate[$date];
                    $lastInflationRawDate = $date;
                }
                $inflationFactor = $currentInflationValue / $inflationStartValue;
            } elseif ($customInflationPct !== null) {
                $elapsedDays = (int) $start->diff($day)->format('%a');
                $inflationFactor = pow(
                    1.0 + $customInflationPct / 100.0,
                    $elapsedDays / 365.2425,
                );
            }
            $nominalBeforeTaxPortfolioValue = $amount * $terAdjustedValue / $startValue;
            $taxResult = TaxAdjustment::calculate(
                $taxAssetSlug,
                $start,
                $day,
                $amount,
                $nominalBeforeTaxPortfolioValue,
                $taxSettings,
            );
            $portfolioValue = $taxResult['afterTaxValue'] / $inflationFactor;
            $beforeTaxPortfolioValue = $nominalBeforeTaxPortfolioValue / $inflationFactor;
            $returnPct = ($portfolioValue / $amount - 1.0) * 100.0;

            $priceOnlyBeforeTaxNominal = $amount * ($currentValue / $startValue) * $terFactor;
            $priceOnlyTaxResult = TaxAdjustment::calculate(
                $taxAssetSlug,
                $start,
                $day,
                $amount,
                $priceOnlyBeforeTaxNominal,
                $taxSettings,
            );
            $priceOnlyPortfolioValue = $priceOnlyTaxResult['afterTaxValue'] / $inflationFactor;
            $priceOnlyReturnPct = ($priceOnlyPortfolioValue / $amount - 1.0) * 100.0;
            $riskAdjustedValue = $terAdjustedValue / $inflationFactor;
            if ($isRaw && $date > $startDate) {
                if ($previousObservedAdjustedValue > 0.0 && $riskAdjustedValue > 0.0) {
                    $observedReturns[] = [
                        'date' => $date,
                        'return' => $riskAdjustedValue / $previousObservedAdjustedValue - 1.0,
                    ];
                }
                $previousObservedAdjustedValue = $riskAdjustedValue;
            }
            $riskPortfolioValue = $amount * $riskAdjustedValue / $startValue;
            $runningPeak = max($runningPeak, $riskPortfolioValue);
            $drawdown = $riskPortfolioValue / $runningPeak - 1.0;
            $maxDrawdown = min($maxDrawdown, $drawdown);

            $points[] = [
                'date' => $date,
                'portfolioValue' => round($portfolioValue, 4),
                'nominalPortfolioValue' => $inflationApplied ? round($taxResult['afterTaxValue'], 4) : null,
                'beforeTaxPortfolioValue' => round($beforeTaxPortfolioValue, 4),
                'nominalBeforeTaxPortfolioValue' => round($nominalBeforeTaxPortfolioValue, 4),
                'returnPct' => round($returnPct, 6),
                'sourceValue' => round($currentValue, 10),
                'dividendAdjustedSourceValue' => round($adjustedValue, 10),
                'rentalCashflowAdjustedSourceValue' => $annualNetRentalCashflowPct > 0.0
                    ? round($totalAdjustedValue, 10)
                    : null,
                'totalAdjustedSourceValue' => round($totalAdjustedValue, 10),
                'terAdjustedSourceValue' => $annualTerPct > 0.0 ? round($terAdjustedValue, 10) : null,
                'terFactor' => $annualTerPct > 0.0 ? round($terFactor, 10) : null,
                'terCostAmount' => $annualTerPct > 0.0
                    ? round($amount * ($totalAdjustedValue - $terAdjustedValue) / $startValue, 4)
                    : 0.0,
                'inflationAdjustedSourceValue' => $inflationApplied
                    ? round(($taxResult['afterTaxValue'] / $amount) * $startValue / $inflationFactor, 10)
                    : null,
                'priceOnlyPortfolioValue' => round($priceOnlyPortfolioValue, 4),
                'priceOnlyReturnPct' => round($priceOnlyReturnPct, 6),
                'priceOnlyTaxAmount' => round($priceOnlyTaxResult['taxAmount'], 4),
                'priceOnlyTaxStatus' => $priceOnlyTaxResult['status'],
                'priceOnlyTaxLabel' => $priceOnlyTaxResult['label'],
                'taxAmount' => round($taxResult['taxAmount'], 4),
                'taxableGain' => round($taxResult['taxableGain'], 4),
                'taxStatus' => $taxResult['status'],
                'taxLabel' => $taxResult['label'],
                'inflationFactor' => $inflationApplied ? round($inflationFactor, 10) : null,
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
            'inflationAdjustment' => [
                'applied' => $inflationApplied,
                'type' => $inflationRows !== [] ? 'historical_index' : ($customInflationPct !== null ? 'custom_rate' : null),
                'annualRatePct' => $customInflationPct === null ? null : round($customInflationPct, 4),
                'lastRawObservationDate' => $lastInflationRawDate,
            ],
            'terAdjustment' => TerAdjustment::metadata(
                $annualTerPct,
                $annualTerPct > 0.0,
                (float) ($lastPoint['terCostAmount'] ?? 0.0),
            ),
            'taxAdjustment' => TaxAdjustment::metadata($taxAssetSlug, $taxSettings, [
                'taxAmount' => $lastPoint['taxAmount'] ?? 0.0,
                'status' => $lastPoint['taxStatus'] ?? 'disabled',
                'label' => $lastPoint['taxLabel'] ?? 'Steuermodell deaktiviert',
            ]),
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
                'dividendAdjustmentApplied' => $annualDividendYields !== []
                    || $dividendMethod === AnnualDividendAdjustment::METHOD,
                'rentalCashflowAdjustmentApplied' => $annualNetRentalCashflowPct > 0.0,
                'annualNetRentalCashflowPct' => round($annualNetRentalCashflowPct, 4),
                'inflationAdjustmentApplied' => $inflationApplied,
                'terAdjustmentApplied' => $annualTerPct > 0.0,
                'annualTerPct' => round($annualTerPct, 4),
                'estimatedTerCost' => round((float) ($lastPoint['terCostAmount'] ?? 0.0), 2),
                'taxAdjustmentApplied' => (bool) ($taxSettings['enabled'] ?? false),
                'beforeTaxFinalValue' => round((float) ($lastPoint['beforeTaxPortfolioValue'] ?? $finalValue), 2),
                'taxAmount' => round((float) ($lastPoint['taxAmount'] ?? 0.0), 2),
                'taxStatus' => $lastPoint['taxStatus'] ?? 'disabled',
                'taxRule' => $lastPoint['taxLabel'] ?? 'Steuermodell deaktiviert',
            ] + $riskMetrics,
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

    private static function annualizationPeriods(AssetDefinition $asset): float
    {
        return match ($asset->nativeFrequency) {
            'monthly' => 12.0,
            'quarterly' => 4.0,
            'annual' => 1.0,
            default => $asset->calendarType === 'calendar_daily' ? 365.2425 : 252.0,
        };
    }

    private static function dividendFactor(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $rows,
        string $method,
    ): float {
        return $method === AnnualDividendAdjustment::METHOD
            ? AnnualDividendAdjustment::factor($from, $to, $rows)
            : DividendAdjustment::factor($from, $to, $rows);
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
