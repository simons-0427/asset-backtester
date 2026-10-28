<?php

declare(strict_types=1);

namespace Smstark\Backtester\Domain;

final class AssetDefinition
{
    public function __construct(
        public readonly int $id,
        public readonly int $sourceId,
        public readonly string $slug,
        public readonly string $displayName,
        public readonly string $shortName,
        public readonly string $assetClass,
        public readonly ?string $region,
        public readonly string $providerSymbol,
        public readonly ?string $providerDataset,
        public readonly ?string $identifierType,
        public readonly ?string $identifierValue,
        public readonly string $rawCurrency,
        public readonly string $displayCurrency,
        public readonly string $valueType,
        public readonly string $nativeFrequency,
        public readonly string $calendarType,
        public readonly ?string $timezoneName,
        public readonly ?string $proxyDescription,
        public readonly ?string $methodologyNote,
        public readonly ?string $firstExpectedDate,
        public readonly int $sortOrder,
        public readonly bool $defaultSelected,
        public readonly string $sourceSlug,
        public readonly string $sourceName,
        public readonly string $providerType,
        public readonly ?string $sourceBaseUrl,
        public readonly ?string $licenseNote,
        public readonly ?string $attributionText,
        public readonly ?string $firstDate = null,
        public readonly ?string $lastDate = null,
        public readonly ?string $lastSuccessfulSyncAt = null,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['source_id'],
            (string) $row['slug'],
            (string) $row['display_name'],
            (string) $row['short_name'],
            (string) $row['asset_class'],
            $row['region'] ?? null,
            (string) $row['provider_symbol'],
            $row['provider_dataset'] ?? null,
            $row['identifier_type'] ?? null,
            $row['identifier_value'] ?? null,
            (string) $row['raw_currency'],
            (string) $row['display_currency'],
            (string) $row['value_type'],
            (string) $row['native_frequency'],
            (string) ($row['calendar_type'] ?? 'market_weekdays'),
            $row['timezone_name'] ?? null,
            $row['proxy_description'] ?? null,
            $row['methodology_note'] ?? null,
            $row['first_expected_date'] ?? null,
            (int) $row['sort_order'],
            (bool) $row['is_default_selected'],
            (string) $row['source_slug'],
            (string) $row['source_name'],
            (string) $row['provider_type'],
            $row['source_base_url'] ?? null,
            $row['license_note'] ?? null,
            $row['attribution_text'] ?? null,
            $row['first_date'] ?? null,
            $row['last_date'] ?? null,
            $row['last_successful_sync_at'] ?? null,
        );
    }

    public function toPublicArray(): array
    {
        return [
            'slug' => $this->slug,
            'displayName' => $this->displayName,
            'shortName' => $this->shortName,
            'assetClass' => $this->assetClass,
            'region' => $this->region,
            'nativeFrequency' => $this->nativeFrequency,
            'currency' => $this->displayCurrency,
            'valueType' => $this->valueType,
            'timezone' => $this->timezoneName,
            'identifierType' => $this->identifierType,
            'identifierValue' => $this->identifierValue,
            'providerSymbol' => $this->providerSymbol,
            'proxyDescription' => $this->proxyDescription,
            'methodologyNote' => $this->methodologyNote,
            'firstDate' => $this->firstDate,
            'lastDate' => $this->lastDate,
            'defaultSelected' => $this->defaultSelected,
            'source' => [
                'name' => $this->sourceName,
                'url' => $this->sourceBaseUrl,
                'attribution' => $this->attributionText,
                'licenseNote' => $this->licenseNote,
                'lastSuccessfulSyncAt' => $this->lastSuccessfulSyncAt,
            ],
        ];
    }
}

