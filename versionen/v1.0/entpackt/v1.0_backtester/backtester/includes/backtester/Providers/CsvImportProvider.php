<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class CsvImportProvider implements HistoricalDataProviderInterface
{
    public function __construct(private readonly array $config)
    {
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $directory = realpath((string) ($this->config['directory'] ?? ''));
        if ($directory === false) {
            throw new ProviderException('CSV-Importverzeichnis fehlt.', 'CSV_DIRECTORY_MISSING');
        }
        $file = $directory . DIRECTORY_SEPARATOR . $asset->slug . '.csv';
        if (!is_file($file)) {
            throw new ProviderException('Keine CSV-Importdatei für ' . $asset->displayName . ' gefunden.', 'CSV_FILE_MISSING');
        }

        return self::parseCsv((string) file_get_contents($file), $asset, $from, $to);
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        $to = new DateTimeImmutable('yesterday');
        return $this->fetchRange($asset, $to->modify(sprintf('-%d days', max(1, $lookbackDays))), $to);
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->providerType === 'csv';
    }

    public static function parseCsv(
        string $csv,
        AssetDefinition $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $headers = array_map('trim', str_getcsv((string) array_shift($lines), ',', '"', '\\'));
        $required = ['date', 'value'];
        foreach ($required as $field) {
            if (!in_array($field, $headers, true)) {
                throw new ProviderException('CSV-Datei benötigt die Spalten date und value.', 'CSV_SCHEMA_MISMATCH');
            }
        }

        $observations = [];
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $utc = new \DateTimeZone('UTC');
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $row = array_combine($headers, array_pad($values, count($headers), null));
            $dateText = (string) ($row['date'] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $utc);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $value = (string) ($row['value'] ?? '');
            if (
                $date === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $date->format('Y-m-d') !== $dateText
                || $dateText < $fromDate
                || $dateText > $toDate
                || !is_numeric($value)
                || (float) $value <= 0
            ) {
                continue;
            }
            $sourceTimestamp = null;
            if (($row['source_timestamp'] ?? '') !== '') {
                try {
                    $sourceTimestamp = new DateTimeImmutable((string) $row['source_timestamp']);
                } catch (\Throwable) {
                    continue;
                }
            }

            $rawValue = $value;
            $currency = strtoupper((string) (($row['currency'] ?? '') ?: $asset->rawCurrency));
            $eurValue = null;
            $fxRateToEur = null;
            $fxObservationDate = null;
            if (strtoupper($asset->rawCurrency) !== 'EUR' && $currency === 'EUR') {
                $rawColumns = array_values(array_filter(
                    array_keys($row),
                    static fn (string $column): bool => str_ends_with($column, '_usd_close'),
                ));
                $usdPerEur = trim((string) ($row['usd_per_eur'] ?? ''));
                $fxDateText = trim((string) ($row['fx_observation_date'] ?? ''));
                $fxDate = DateTimeImmutable::createFromFormat('!Y-m-d', $fxDateText, $utc);
                if (
                    count($rawColumns) !== 1
                    || !is_numeric((string) ($row[$rawColumns[0]] ?? null))
                    || (float) $row[$rawColumns[0]] <= 0
                    || !is_numeric($usdPerEur)
                    || (float) $usdPerEur <= 0
                    || $fxDate === false
                    || $fxDate->format('Y-m-d') !== $fxDateText
                ) {
                    continue;
                }
                $rawValue = (string) $row[$rawColumns[0]];
                $currency = strtoupper($asset->rawCurrency);
                $eurValue = $value;
                $fxRateToEur = self::decimal(1 / (float) $usdPerEur, 12);
                $fxObservationDate = $fxDate;
            }
            $observations[] = new HistoricalObservation(
                $date,
                $rawValue,
                $currency,
                $sourceTimestamp,
                (string) (($row['quality_status'] ?? '') ?: 'valid'),
                ($row['source_revision'] ?? '') ?: null,
                hash('sha256', $line),
                $eurValue,
                $fxRateToEur,
                $fxObservationDate,
            );
        }
        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        return $observations;
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
