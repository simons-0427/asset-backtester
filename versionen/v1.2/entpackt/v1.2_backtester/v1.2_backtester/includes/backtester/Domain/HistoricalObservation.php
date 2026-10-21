<?php

declare(strict_types=1);

namespace Smstark\Backtester\Domain;

use DateTimeImmutable;

final class HistoricalObservation
{
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly string $value,
        public readonly string $currency,
        public readonly ?DateTimeImmutable $sourceTimestamp = null,
        public readonly string $qualityStatus = 'valid',
        public readonly ?string $sourceRevision = null,
        public readonly ?string $rawPayloadHash = null,
        public readonly ?string $eurValue = null,
        public readonly ?string $fxRateToEur = null,
        public readonly ?DateTimeImmutable $fxObservationDate = null,
    ) {
    }

    public function toStorageArray(int $assetId): array
    {
        $isEur = strtoupper($this->currency) === 'EUR';
        return [
            'asset_id' => $assetId,
            'observation_date' => $this->date->format('Y-m-d'),
            'raw_value' => $this->value,
            'raw_currency' => strtoupper($this->currency),
            'eur_value' => $this->eurValue ?? ($isEur ? $this->value : null),
            'fx_rate_to_eur' => $this->fxRateToEur ?? ($isEur ? '1' : null),
            'fx_observation_date' => ($this->fxObservationDate ?? ($isEur ? $this->date : null))?->format('Y-m-d'),
            'source_timestamp' => $this->sourceTimestamp?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'quality_status' => $this->qualityStatus,
            'source_revision' => $this->sourceRevision,
            'raw_payload_hash' => $this->rawPayloadHash,
        ];
    }
}
