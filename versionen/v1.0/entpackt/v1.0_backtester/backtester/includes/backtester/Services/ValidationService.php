<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class ValidationService
{
    public function validate(array $observations, AssetDefinition $asset): array
    {
        $seen = [];
        $validated = [];
        $previousValue = null;
        $futureLimit = (new DateTimeImmutable('today'))->modify('+1 day');

        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        foreach ($observations as $observation) {
            if (!$observation instanceof HistoricalObservation) {
                throw new ProviderException('Provider lieferte einen ungültigen Datenpunkt.', 'INVALID_OBSERVATION');
            }
            $date = $observation->date->format('Y-m-d');
            $value = (float) $observation->value;
            if (isset($seen[$date])) {
                throw new ProviderException('Provider lieferte doppelte Tageswerte.', 'DUPLICATE_OBSERVATION');
            }
            if (!is_finite($value) || $value <= 0 || $observation->date > $futureLimit) {
                throw new ProviderException('Provider lieferte einen fachlich ungültigen Datenpunkt.', 'INVALID_OBSERVATION');
            }
            if (strtoupper($observation->currency) !== strtoupper($asset->rawCurrency)) {
                throw new ProviderException('Währung der Providerantwort stimmt nicht mit dem Asset überein.', 'CURRENCY_MISMATCH');
            }

            $quality = $observation->qualityStatus;
            if ($previousValue !== null) {
                $ratio = $value / $previousValue;
                if ($ratio > 5.0 || $ratio < 0.2) {
                    $quality = 'flagged';
                }
            }
            $validated[] = $quality === $observation->qualityStatus
                ? $observation
                : new HistoricalObservation(
                    $observation->date,
                    $observation->value,
                    $observation->currency,
                    $observation->sourceTimestamp,
                    $quality,
                    $observation->sourceRevision,
                    $observation->rawPayloadHash,
                    $observation->eurValue,
                    $observation->fxRateToEur,
                    $observation->fxObservationDate,
                );
            $seen[$date] = true;
            $previousValue = $value;
        }
        return $validated;
    }
}
