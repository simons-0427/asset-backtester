<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;

final class RentalCashflowAdjustment
{
    public const METHOD = 'simple_net_cashflow_on_initial_capital';

    public static function indexIncrement(
        DateTimeImmutable $start,
        DateTimeImmutable $date,
        float $startIndexValue,
        float $annualNetCashflowPct,
    ): float {
        if ($annualNetCashflowPct <= 0.0 || $date <= $start || $startIndexValue <= 0.0) {
            return 0.0;
        }
        $days = (int) $start->diff($date)->format('%a');
        return $startIndexValue * ($annualNetCashflowPct / 100.0) * ($days / 365.2425);
    }

    public static function growthIncrement(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        float $annualNetCashflowPct,
    ): float {
        if ($annualNetCashflowPct <= 0.0 || $end <= $start) {
            return 0.0;
        }
        $days = (int) $start->diff($end)->format('%a');
        return ($annualNetCashflowPct / 100.0) * ($days / 365.2425);
    }

    public static function metadata(float $annualNetCashflowPct, bool $applied): array
    {
        return [
            'applied' => $applied,
            'method' => self::METHOD,
            'annualNetCashflowPct' => round($annualNetCashflowPct, 4),
            'reinvested' => false,
            'basis' => 'initial_capital',
        ];
    }
}
