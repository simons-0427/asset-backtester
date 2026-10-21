<?php

declare(strict_types=1);

namespace Smstark\Backtester\Tools;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class BtcUsdDailyPreparer
{
    private const UTC = 'UTC';

    /**
     * @return array<string, int|string>
     */
    public function prepare(
        string $minuteFile,
        string $ecbFile,
        string $outputFile,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $utc = new DateTimeZone(self::UTC);
        $from = $from->setTimezone($utc)->setTime(0, 0);
        $to = $to->setTimezone($utc)->setTime(0, 0);
        if ($from > $to) {
            throw new RuntimeException('Das Startdatum muss vor oder am Enddatum liegen.');
        }
        if (!is_file($minuteFile) || !is_readable($minuteFile)) {
            throw new RuntimeException('Die BTC/USD-Minuten-CSV ist nicht lesbar.');
        }
        if (!is_file($ecbFile) || !is_readable($ecbFile)) {
            throw new RuntimeException('Die EZB-Wechselkurs-CSV ist nicht lesbar.');
        }

        $fxByDay = $this->dailyFxRates($ecbFile, $from, $to);
        $scan = $this->dailyCloses($minuteFile, $from, $to);
        $daily = $scan['daily'];
        $missing = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if (!isset($daily[$date])) {
                $missing[] = $date;
            }
        }
        if ($missing !== []) {
            $preview = implode(', ', array_slice($missing, 0, 10));
            throw new RuntimeException(sprintf(
                'Die Minuten-CSV enthält %d vollständige Tageslücken. Erste Lücken: %s',
                count($missing),
                $preview,
            ));
        }

        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Ausgabe-Verzeichnis konnte nicht erstellt werden.');
        }
        $temporary = tempnam($directory, 'bitcoin-daily-');
        if ($temporary === false) {
            throw new RuntimeException('Die temporäre Ausgabedatei konnte nicht erstellt werden.');
        }

        $written = 0;
        $carriedFxDays = 0;
        $nonFinalMinuteDays = 0;
        $maxCloseLagMinutes = 0;
        $output = fopen($temporary, 'wb');
        if ($output === false) {
            @unlink($temporary);
            throw new RuntimeException('Die Ausgabedatei konnte nicht geöffnet werden.');
        }

        try {
            fputcsv($output, [
                'date',
                'value',
                'currency',
                'quality_status',
                'source_revision',
                'source_timestamp',
                'btc_usd_close',
                'usd_per_eur',
                'fx_observation_date',
            ], ',', '"', '');

            for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $close = $daily[$date];
                $fx = $fxByDay[$date];
                $eurValue = (float) $close['value'] / (float) $fx['rate'];
                $timestamp = (int) $close['timestamp'];
                $expectedLastMinute = $day->setTime(23, 59)->getTimestamp();
                $lagMinutes = max(0, intdiv($expectedLastMinute - $timestamp, 60));
                if ($lagMinutes > 0) {
                    $nonFinalMinuteDays++;
                    $maxCloseLagMinutes = max($maxCloseLagMinutes, $lagMinutes);
                }
                if ($fx['date'] !== $date) {
                    $carriedFxDays++;
                }

                fputcsv($output, [
                    $date,
                    number_format($eurValue, 10, '.', ''),
                    'EUR',
                    'valid',
                    sprintf('kaggle-btcusd-1m:%d;ecb-usd-eur:%s', $timestamp, $fx['date']),
                    gmdate('Y-m-d\TH:i:s\Z', $timestamp),
                    $close['value'],
                    $fx['rate'],
                    $fx['date'],
                ], ',', '"', '');
                $written++;
            }
        } finally {
            fclose($output);
        }

        if (is_file($outputFile) && !unlink($outputFile)) {
            @unlink($temporary);
            throw new RuntimeException('Die vorhandene Ausgabedatei konnte nicht ersetzt werden.');
        }
        if (!rename($temporary, $outputFile)) {
            @unlink($temporary);
            throw new RuntimeException('Die fertige Ausgabedatei konnte nicht übernommen werden.');
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days_written' => $written,
            'source_rows_read' => $scan['rows_read'],
            'source_rows_in_range' => $scan['rows_in_range'],
            'invalid_rows_in_range' => $scan['invalid_rows_in_range'],
            'duplicate_timestamps_in_range' => $scan['duplicate_timestamps_in_range'],
            'out_of_order_rows' => $scan['out_of_order_rows'],
            'days_with_carried_fx_rate' => $carriedFxDays,
            'days_without_2359_close' => $nonFinalMinuteDays,
            'maximum_close_lag_minutes' => $maxCloseLagMinutes,
            'output_file' => $outputFile,
        ];
    }

    /**
     * @return array<string, array{rate: string, date: string}>
     */
    private function dailyFxRates(
        string $file,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Die EZB-Wechselkurs-CSV konnte nicht geöffnet werden.');
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (!is_array($header)) {
                throw new RuntimeException('Die EZB-Wechselkurs-CSV besitzt keinen Header.');
            }
            $index = $this->headerIndex($header);
            foreach (['time_period', 'obs_value', 'currency', 'currency_denom'] as $required) {
                if (!isset($index[$required])) {
                    throw new RuntimeException('In der EZB-Datei fehlt die Spalte ' . strtoupper($required) . '.');
                }
            }

            $rates = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $currency = strtoupper(trim((string) ($row[$index['currency']] ?? '')));
                $denominator = strtoupper(trim((string) ($row[$index['currency_denom']] ?? '')));
                $date = trim((string) ($row[$index['time_period']] ?? ''));
                $value = trim((string) ($row[$index['obs_value']] ?? ''));
                if ($currency !== 'USD' || $denominator !== 'EUR' || !$this->isIsoDate($date)) {
                    continue;
                }
                if (!is_numeric($value) || (float) $value <= 0) {
                    continue;
                }
                $rates[$date] = $value;
            }
        } finally {
            fclose($handle);
        }

        if ($rates === []) {
            throw new RuntimeException('Die EZB-Datei enthält keine gültigen USD/EUR-Referenzkurse.');
        }
        ksort($rates);
        $dates = array_keys($rates);
        $cursor = 0;
        $currentRate = null;
        $currentDate = null;
        $result = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            while (isset($dates[$cursor]) && $dates[$cursor] <= $date) {
                $currentDate = $dates[$cursor];
                $currentRate = $rates[$currentDate];
                $cursor++;
            }
            if ($currentRate === null || $currentDate === null) {
                throw new RuntimeException('Vor ' . $date . ' fehlt ein verwendbarer EZB-Referenzkurs.');
            }
            $result[$date] = ['rate' => $currentRate, 'date' => $currentDate];
        }
        return $result;
    }

    /**
     * @return array{daily: array<string, array{timestamp: int, value: string}>, rows_read: int,
     *     rows_in_range: int, invalid_rows_in_range: int, duplicate_timestamps_in_range: int,
     *     out_of_order_rows: int}
     */
    private function dailyCloses(
        string $file,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Die BTC/USD-Minuten-CSV konnte nicht geöffnet werden.');
        }
        $daily = [];
        $rowsRead = 0;
        $rowsInRange = 0;
        $invalidRows = 0;
        $duplicateTimestamps = 0;
        $outOfOrderRows = 0;
        $lastTimestamp = null;
        $fromTimestamp = $from->getTimestamp();
        $toTimestamp = $to->setTime(23, 59, 59)->getTimestamp();

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (!is_array($header)) {
                throw new RuntimeException('Die BTC/USD-Minuten-CSV besitzt keinen Header.');
            }
            $index = $this->headerIndex($header);
            foreach (['timestamp', 'close'] as $required) {
                if (!isset($index[$required])) {
                    throw new RuntimeException('In der Minuten-Datei fehlt die Spalte ' . ucfirst($required) . '.');
                }
            }

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rowsRead++;
                $rawTimestamp = trim((string) ($row[$index['timestamp']] ?? ''));
                if (!is_numeric($rawTimestamp)) {
                    continue;
                }
                $timestamp = (int) floor((float) $rawTimestamp);
                $isDuplicate = $lastTimestamp !== null && $timestamp === $lastTimestamp;
                if ($lastTimestamp !== null && $timestamp < $lastTimestamp) {
                    $outOfOrderRows++;
                }
                $lastTimestamp = $timestamp;
                if ($timestamp < $fromTimestamp || $timestamp > $toTimestamp) {
                    continue;
                }
                $rowsInRange++;
                $close = trim((string) ($row[$index['close']] ?? ''));
                if (!is_numeric($close) || !is_finite((float) $close) || (float) $close <= 0) {
                    $invalidRows++;
                    continue;
                }
                if ($isDuplicate) {
                    $duplicateTimestamps++;
                }
                $date = gmdate('Y-m-d', $timestamp);
                if (!isset($daily[$date]) || $timestamp >= $daily[$date]['timestamp']) {
                    $daily[$date] = ['timestamp' => $timestamp, 'value' => $close];
                }
            }
        } finally {
            fclose($handle);
        }

        ksort($daily);
        return [
            'daily' => $daily,
            'rows_read' => $rowsRead,
            'rows_in_range' => $rowsInRange,
            'invalid_rows_in_range' => $invalidRows,
            'duplicate_timestamps_in_range' => $duplicateTimestamps,
            'out_of_order_rows' => $outOfOrderRows,
        ];
    }

    /**
     * @param array<int, string|null> $header
     * @return array<string, int>
     */
    private function headerIndex(array $header): array
    {
        $index = [];
        foreach ($header as $position => $name) {
            $normalized = strtolower(trim((string) $name));
            if ($position === 0) {
                $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;
            }
            $index[$normalized] = $position;
        }
        return $index;
    }

    private function isIsoDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::UTC));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }
}
