<?php

declare(strict_types=1);

namespace Smstark\Backtester\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class DividendYieldObservation
{
    public function __construct(
        public readonly DateTimeImmutable $sourceObservationDate,
        public readonly string $annualYieldPct,
        public readonly bool $isEstimate,
        public readonly string $sourceUrl,
        public readonly ?string $rawPayloadHash = null,
    ) {
    }

    public function observationMonth(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $this->sourceObservationDate->format('Y-m-01'),
            new DateTimeZone('UTC'),
        );
    }

    public function sourceRevision(): string
    {
        return sprintf(
            'multpl:%s:%s',
            $this->sourceObservationDate->format('Y-m-d'),
            $this->isEstimate ? 'estimate' : 'final',
        );
    }

    public function toStorageArray(int $assetId): array
    {
        return [
            'asset_id' => $assetId,
            'observation_month' => $this->observationMonth()->format('Y-m-d'),
            'source_observation_date' => $this->sourceObservationDate->format('Y-m-d'),
            'annual_yield_pct' => $this->annualYieldPct,
            'is_estimate' => $this->isEstimate ? 1 : 0,
            'source_url' => $this->sourceUrl,
            'source_revision' => $this->sourceRevision(),
            'raw_payload_hash' => $this->rawPayloadHash,
        ];
    }
}
