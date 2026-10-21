<?php

declare(strict_types=1);

namespace Smstark\Backtester\Http;

use DateTimeImmutable;
use Smstark\Backtester\Exceptions\ValidationException;

final class RequestValidator
{
    private const INFLATION_SLUGS = [
        'inflation_de',
        'inflation_euro_area',
        'inflation_us',
    ];

    public function __construct(private readonly array $config)
    {
    }

    public function series(array $query, array $allowedSlugs): array
    {
        $assetInput = (string) ($query['assets'] ?? '');
        $assets = array_values(array_unique(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $assetInput)
        ))));

        if ($assets === []) {
            throw new ValidationException('Mindestens eine Anlageklasse muss ausgewählt sein.', 'ASSETS_REQUIRED');
        }
        if (count($assets) > max(20, (int) $this->config['max_assets_per_request'])) {
            throw new ValidationException('Zu viele Anlageklassen ausgewählt.', 'TOO_MANY_ASSETS');
        }
        foreach ($assets as $slug) {
            if (!in_array($slug, $allowedSlugs, true)) {
                throw new ValidationException('Unbekannte oder inaktive Anlageklasse.', 'INVALID_ASSET');
            }
        }

        $start = $this->date((string) ($query['start'] ?? ''), 'START_DATE_INVALID');
        $end = $this->date((string) ($query['end'] ?? ''), 'END_DATE_INVALID');
        if ($start >= $end) {
            throw new ValidationException('Das Startdatum muss vor dem Enddatum liegen.', 'INVALID_DATE_RANGE');
        }

        $today = new DateTimeImmutable('today');
        if ($end > $today) {
            throw new ValidationException('Das Enddatum darf höchstens heute sein.', 'END_DATE_IN_FUTURE');
        }

        $days = (int) $start->diff($end)->format('%a');
        if ($days > (int) $this->config['max_range_days']) {
            throw new ValidationException('Der gewählte Zeitraum ist zu lang.', 'RANGE_TOO_LONG');
        }

        $amount = filter_var($query['amount'] ?? 10000, FILTER_VALIDATE_FLOAT);
        if ($amount === false
            || $amount < (float) $this->config['min_amount']
            || $amount > (float) $this->config['max_amount']) {
            throw new ValidationException('Das Startkapital liegt außerhalb des zulässigen Bereichs.', 'INVALID_AMOUNT');
        }

        $inflation = strtolower(trim((string) ($query['inflation'] ?? 'none')));
        if (!in_array($inflation, array_merge(['none', 'custom'], self::INFLATION_SLUGS), true)) {
            throw new ValidationException('Unbekannte Inflationsreihe.', 'INVALID_INFLATION');
        }
        $customInflationPct = null;
        if ($inflation === 'custom') {
            $customInflationPct = filter_var($query['customInflationPct'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($customInflationPct === false || $customInflationPct < -5.0 || $customInflationPct > 30.0) {
                throw new ValidationException(
                    'Die eigene Inflation muss zwischen -5 und 30 Prozent pro Jahr liegen.',
                    'INVALID_CUSTOM_INFLATION',
                );
            }
            $customInflationPct = (float) $customInflationPct;
        }

        $includeTer = filter_var($query['includeTer'] ?? false, FILTER_VALIDATE_BOOL);
        $terPct = filter_var($query['terPct'] ?? 0.0, FILTER_VALIDATE_FLOAT);
        if ($terPct === false || $terPct < 0.0 || $terPct > 5.0) {
            throw new ValidationException('Die TER muss zwischen 0 und 5 Prozent pro Jahr liegen.', 'INVALID_TER');
        }

        $includeTaxes = filter_var($query['includeTaxes'] ?? false, FILTER_VALIDATE_BOOL);
        $personalIncomeTaxPct = $this->boundedFloat(
            $query['personalIncomeTaxPct'] ?? 42.0,
            0.0,
            45.0,
            'Der persönliche Einkommensteuersatz muss zwischen 0 und 45 Prozent liegen.',
            'INVALID_PERSONAL_TAX_RATE',
        );
        $capitalAllowance = $this->boundedFloat(
            $query['capitalAllowance'] ?? 1000.0,
            0.0,
            10000.0,
            'Der Sparer-Pauschbetrag muss zwischen 0 und 10.000 Euro liegen.',
            'INVALID_CAPITAL_ALLOWANCE',
        );
        $churchTaxPct = $this->boundedFloat(
            $query['churchTaxPct'] ?? 0.0,
            0.0,
            9.0,
            'Die Kirchensteuer muss 0, 8 oder 9 Prozent betragen.',
            'INVALID_CHURCH_TAX_RATE',
        );
        if (!in_array($churchTaxPct, [0.0, 8.0, 9.0], true)) {
            throw new ValidationException('Die Kirchensteuer muss 0, 8 oder 9 Prozent betragen.', 'INVALID_CHURCH_TAX_RATE');
        }

        return [
            'assets' => $assets,
            'start' => $start,
            'end' => $end,
            'amount' => (float) $amount,
            'netRentalCashflowPct' => $this->netRentalCashflowPct($query['netRentPct'] ?? null),
            'deNetRentalCashflowPct' => $this->deNetRentalCashflowPct($query['deNetRentPct'] ?? null),
            'includeRentalCashflow' => filter_var(
                $query['includeRentalCashflow'] ?? false,
                FILTER_VALIDATE_BOOL,
            ),
            'inflationSlug' => in_array($inflation, self::INFLATION_SLUGS, true) ? $inflation : null,
            'customInflationPct' => $customInflationPct,
            'includeTer' => $includeTer,
            'terPct' => (float) $terPct,
            'taxSettings' => [
                'enabled' => $includeTaxes,
                'personalIncomeTaxPct' => $personalIncomeTaxPct,
                'capitalAllowance' => $capitalAllowance,
                'churchTaxPct' => $churchTaxPct,
            ],
        ];
    }

    public function netRentalCashflowPct(mixed $value): float
    {
        return $this->validatedRentalCashflowPct(
            $value,
            (float) ($this->config['default_net_rental_cashflow_pct'] ?? 5.0),
        );
    }

    public function deNetRentalCashflowPct(mixed $value): float
    {
        return $this->validatedRentalCashflowPct(
            $value,
            (float) ($this->config['default_de_net_rental_cashflow_pct'] ?? 3.0),
        );
    }

    private function validatedRentalCashflowPct(mixed $value, float $default): float
    {
        $value = $value === null || $value === '' ? $default : $value;
        $rate = filter_var($value, FILTER_VALIDATE_FLOAT);
        $minimum = (float) ($this->config['min_net_rental_cashflow_pct'] ?? 0.0);
        $maximum = (float) ($this->config['max_net_rental_cashflow_pct'] ?? 15.0);
        if ($rate === false || $rate < $minimum || $rate > $maximum) {
            throw new ValidationException(
                'Der Netto-Mietcashflow muss zwischen 0 und 15 Prozent pro Jahr liegen.',
                'INVALID_NET_RENTAL_CASHFLOW',
            );
        }
        return (float) $rate;
    }

    private function boundedFloat(
        mixed $value,
        float $minimum,
        float $maximum,
        string $message,
        string $errorCode,
    ): float {
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || $number < $minimum || $number > $maximum) {
            throw new ValidationException($message, $errorCode);
        }
        return (float) $number;
    }

    public function optionalEnd(mixed $value): DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new DateTimeImmutable('today');
        }
        $date = $this->date((string) $value, 'END_DATE_INVALID');
        if ($date > new DateTimeImmutable('today')) {
            throw new ValidationException('Das Enddatum darf nicht in der Zukunft liegen.', 'END_DATE_IN_FUTURE');
        }
        return $date;
    }

    private function date(string $value, string $errorCode): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ValidationException('Datum muss im Format JJJJ-MM-TT angegeben werden.', $errorCode);
        }
        return $date;
    }
}
