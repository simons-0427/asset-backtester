<?php

declare(strict_types=1);

namespace Smstark\Backtester\Http;

use DateTimeImmutable;
use Smstark\Backtester\Exceptions\ValidationException;

final class RequestValidator
{
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
        if (count($assets) > (int) $this->config['max_assets_per_request']) {
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

        $yesterday = (new DateTimeImmutable('today'))->modify('-1 day');
        if ($end > $yesterday) {
            throw new ValidationException('Das Enddatum darf höchstens der letzte abgeschlossene Kalendertag sein.', 'END_DATE_IN_FUTURE');
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

        return [
            'assets' => $assets,
            'start' => $start,
            'end' => $end,
            'amount' => (float) $amount,
        ];
    }

    public function optionalEnd(mixed $value): DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return (new DateTimeImmutable('today'))->modify('-1 day');
        }
        $date = $this->date((string) $value, 'END_DATE_INVALID');
        if ($date > (new DateTimeImmutable('today'))->modify('-1 day')) {
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

