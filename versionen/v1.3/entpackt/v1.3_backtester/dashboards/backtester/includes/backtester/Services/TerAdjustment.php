<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;

final class TerAdjustment
{
    public static function factor(
        DateTimeImmutable $start,
        DateTimeImmutable $date,
        float $annualTerPct,
    ): float {
        if ($annualTerPct <= 0.0 || $date <= $start) {
            return 1.0;
        }

        $elapsedDays = (int) $start->diff($date)->format('%a');
        return pow(1.0 - $annualTerPct / 100.0, $elapsedDays / 365.2425);
    }

    public static function metadata(float $annualTerPct, bool $applied, float $estimatedCostAtEnd = 0.0): array
    {
        return [
            'applied' => $applied,
            'annualTerPct' => round($annualTerPct, 4),
            'method' => 'daily_compounded_annual_expense_ratio',
            'estimatedCostAtEnd' => round(max(0.0, $estimatedCostAtEnd), 2),
        ];
    }
}
