<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;

final class TaxAdjustment
{
    private const EQUITY_ETF_SLUGS = ['sp500', 'msci_world', 'dax', 'eurostoxx50'];
    private const PRIVATE_ONE_YEAR_SLUGS = ['bitcoin', 'gold'];
    private const PRIVATE_TEN_YEAR_SLUGS = ['de_real_estate', 'us_real_estate'];

    public static function calculate(
        string $assetSlug,
        DateTimeImmutable $start,
        DateTimeImmutable $date,
        float $initialAmount,
        float $beforeTaxValue,
        array $settings,
    ): array {
        if (!(bool) ($settings['enabled'] ?? false)) {
            return self::result($beforeTaxValue, 0.0, 'disabled', 'Steuermodell deaktiviert');
        }

        $gain = max(0.0, $beforeTaxValue - $initialAmount);
        if ($gain <= 0.0) {
            return self::result($beforeTaxValue, 0.0, 'no_gain', 'Kein positiver Gewinn');
        }

        if (in_array($assetSlug, self::PRIVATE_ONE_YEAR_SLUGS, true)) {
            return self::privateSale(
                $assetSlug,
                $start,
                $date,
                $initialAmount,
                $beforeTaxValue,
                $gain,
                1,
                $settings,
            );
        }

        if (in_array($assetSlug, self::PRIVATE_TEN_YEAR_SLUGS, true)) {
            return self::privateSale(
                $assetSlug,
                $start,
                $date,
                $initialAmount,
                $beforeTaxValue,
                $gain,
                10,
                $settings,
            );
        }

        $partialExemptionPct = in_array($assetSlug, self::EQUITY_ETF_SLUGS, true) ? 30.0 : 0.0;
        $capitalAllowance = max(0.0, (float) ($settings['capitalAllowance'] ?? 1000.0));
        $taxableGain = max(0.0, $gain * (1.0 - $partialExemptionPct / 100.0) - $capitalAllowance);
        if ($taxableGain <= 0.0) {
            $label = $partialExemptionPct > 0.0
                ? 'Aktien-ETF: 30 % Teilfreistellung; durch Pauschbetrag steuerfrei'
                : 'Durch Sparer-Pauschbetrag steuerfrei';
            return self::result($beforeTaxValue, 0.0, 'capital_allowance', $label, $taxableGain);
        }

        $churchTaxRate = max(0.0, (float) ($settings['churchTaxPct'] ?? 0.0)) / 100.0;
        $capitalIncomeTax = $taxableGain * (0.25 / (1.0 + $churchTaxRate / 4.0));
        $solidaritySurcharge = $capitalIncomeTax * 0.055;
        $churchTax = $capitalIncomeTax * $churchTaxRate;
        $taxAmount = $capitalIncomeTax + $solidaritySurcharge + $churchTax;
        $label = $partialExemptionPct > 0.0
            ? 'Aktien-ETF: 30 % Teilfreistellung, Abgeltungsteuer und Soli'
            : 'Abgeltungsteuer und Soli';
        if ($churchTaxRate > 0.0) {
            $label .= ', inkl. Kirchensteuer';
        }

        return self::result(
            max($initialAmount, $beforeTaxValue - $taxAmount),
            $taxAmount,
            'capital_income_tax',
            $label,
            $taxableGain,
        );
    }

    public static function metadata(string $assetSlug, array $settings, array $lastResult): array
    {
        return [
            'applied' => (bool) ($settings['enabled'] ?? false),
            'method' => 'hypothetical_full_sale_on_each_chart_date',
            'profile' => self::profile($assetSlug),
            'personalIncomeTaxPct' => round((float) ($settings['personalIncomeTaxPct'] ?? 42.0), 4),
            'capitalAllowance' => round((float) ($settings['capitalAllowance'] ?? 1000.0), 2),
            'churchTaxPct' => round((float) ($settings['churchTaxPct'] ?? 0.0), 4),
            'taxAmountAtEnd' => round((float) ($lastResult['taxAmount'] ?? 0.0), 2),
            'statusAtEnd' => $lastResult['status'] ?? 'disabled',
            'labelAtEnd' => $lastResult['label'] ?? 'Steuermodell deaktiviert',
        ];
    }

    public static function profile(string $assetSlug): string
    {
        return match (true) {
            in_array($assetSlug, self::EQUITY_ETF_SLUGS, true) => 'equity_etf',
            in_array($assetSlug, self::PRIVATE_ONE_YEAR_SLUGS, true) => 'private_sale_one_year',
            in_array($assetSlug, self::PRIVATE_TEN_YEAR_SLUGS, true) => 'private_sale_ten_years',
            default => 'capital_investment',
        };
    }

    private static function privateSale(
        string $assetSlug,
        DateTimeImmutable $start,
        DateTimeImmutable $date,
        float $initialAmount,
        float $beforeTaxValue,
        float $gain,
        int $holdingYears,
        array $settings,
    ): array {
        $isInsideHoldingPeriod = $date < $start->modify(sprintf('+%d years', $holdingYears));
        $assetName = $assetSlug === 'bitcoin' ? 'Bitcoin' : ($assetSlug === 'gold' ? 'Gold' : 'Immobilien');
        if (!$isInsideHoldingPeriod) {
            return self::result(
                $beforeTaxValue,
                0.0,
                'tax_free_holding_period',
                sprintf('%s: steuerfrei nach %d Jahr%s Haltedauer', $assetName, $holdingYears, $holdingYears === 1 ? '' : 'en'),
            );
        }

        if ($gain < 1000.0) {
            return self::result(
                $beforeTaxValue,
                0.0,
                'tax_free_private_sale_threshold',
                sprintf('%s: private Veräußerung, unter 1.000-€-Freigrenze', $assetName),
            );
        }

        $rate = max(0.0, (float) ($settings['personalIncomeTaxPct'] ?? 42.0)) / 100.0;
        $taxAmount = $gain * $rate;
        return self::result(
            max($initialAmount, $beforeTaxValue - $taxAmount),
            $taxAmount,
            'taxable_private_sale',
            sprintf(
                '%s: Einkommensteuer-Modell bei Verkauf vor %d Jahr%s',
                $assetName,
                $holdingYears,
                $holdingYears === 1 ? '' : 'en',
            ),
            $gain,
        );
    }

    private static function result(
        float $afterTaxValue,
        float $taxAmount,
        string $status,
        string $label,
        float $taxableGain = 0.0,
    ): array {
        return [
            'afterTaxValue' => $afterTaxValue,
            'taxAmount' => max(0.0, $taxAmount),
            'taxableGain' => max(0.0, $taxableGain),
            'status' => $status,
            'label' => $label,
        ];
    }
}
