<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt\Import;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

final class DataCollector
{
    private array $rows = [];
    private array $errors = [];
    private array $sources = [];
    private float $deadline = PHP_FLOAT_MAX;

    public function __construct(
        private readonly HttpClient $http,
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
        int $budgetSeconds = 700,
    ) {
        $this->deadline = microtime(true) + max(20, $budgetSeconds);
    }

    public function collect(string $method): array
    {
        if (!method_exists($this, $method) || !str_starts_with($method, 'collect')) {
            throw new RuntimeException('Unbekannte Collector-Methode.');
        }

        $this->{$method}();
        ksort($this->rows);

        return [
            'rows' => array_values($this->rows),
            'errors' => $this->errors,
            'sources' => array_keys($this->sources),
        ];
    }

    private function safe(string $label, callable $callback): mixed
    {
        if (microtime(true) >= $this->deadline) {
            $this->errors[] = ['source' => $label, 'code' => 'TIME_BUDGET_EXHAUSTED'];
            return null;
        }
        try {
            return $callback();
        } catch (\Throwable $error) {
            $details = [
                'source' => $label,
                'code' => $this->errorCode($error),
            ];
            if (getenv('WELTMARKT_IMPORT_DEBUG') === '1') {
                $details['message'] = substr($error->getMessage(), 0, 500);
            }
            $this->errors[] = $details;
            return null;
        }
    }

    private function touch(string $sourceSlug): void
    {
        $this->sources[$sourceSlug] = true;
    }

    private function add(
        string $metricId,
        ?string $date,
        mixed $value,
        string $quality,
        string $revision,
        ?string $sourceTimestamp = null,
    ): void {
        $number = $this->number($value);
        if ($date === null || $number === null || !$this->isIsoDate($date)) {
            return;
        }
        if ($metricId === 'boj_bs' && $number <= 0.0) {
            return;
        }
        if ($date < $this->from->format('Y-m-d') || $date > $this->to->modify('+1 day')->format('Y-m-d')) {
            return;
        }
        if (preg_match('/^[a-z0-9_]{1,96}$/', $metricId) !== 1) {
            throw new RuntimeException('Ungültige Kennzahl-ID.');
        }
        if (!in_array($quality, ['valid', 'provisional', 'revised', 'flagged'], true)) {
            throw new RuntimeException('Ungültiger Qualitätsstatus.');
        }

        $hashPayload = implode('|', [
            $metricId,
            $date,
            sprintf('%.10F', $number),
            $quality,
            $sourceTimestamp ?? '',
        ]);
        $this->rows[$metricId . '|' . $date] = [
            'metric_id' => $metricId,
            'date' => $date,
            'value' => $number,
            'quality' => $quality,
            'revision' => substr($revision, 0, 128),
            'source_timestamp' => $sourceTimestamp,
            'hash' => hash('sha256', $hashPayload),
        ];
    }

    private function addPeriodRows(
        string $metricId,
        array $rows,
        callable $transform,
        string $quality,
        string $revision,
    ): void {
        foreach ($rows as $row) {
            $date = $row['date'] ?? $this->periodToDate((string) ($row['period'] ?? ''));
            $rowQuality = ($row['status'] ?? '') === 'P' ? 'provisional' : $quality;
            $this->add(
                $metricId,
                $date,
                $transform($row['value'] ?? null, $row, $rows),
                $rowQuality,
                $revision,
                $row['source_timestamp'] ?? null,
            );
        }
    }

    private function addYoY(string $metricId, array $rows, string $quality, string $revision): void
    {
        $normalized = $this->normalizedRows($rows);
        $byDate = [];
        foreach ($normalized as $row) {
            $byDate[$row['date']] = $row['value'];
        }
        foreach ($normalized as $row) {
            $yearAgo = ((int) substr($row['date'], 0, 4) - 1) . substr($row['date'], 4);
            $base = $byDate[$yearAgo] ?? null;
            if ($base === null || $base == 0.0) {
                continue;
            }
            $this->add($metricId, $row['date'], ($row['value'] / $base - 1.0) * 100.0, $quality, $revision);
        }
    }

    private function addMonthlyChange(string $metricId, array $rows, string $revision): void
    {
        $rows = $this->normalizedRows($rows);
        for ($index = 1; $index < count($rows); $index++) {
            $current = $rows[$index];
            $previous = $rows[$index - 1];
            $expected = (new DateTimeImmutable($current['date'] . ' 00:00:00', new DateTimeZone('UTC')))
                ->modify('first day of previous month')->format('Y-m');
            if (substr($previous['date'], 0, 7) === $expected) {
                $this->add($metricId, $current['date'], $current['value'] - $previous['value'], 'valid', $revision);
            }
        }
    }

    private function addMonthlyPctChange(string $metricId, array $rows, string $quality, string $revision): void
    {
        $rows = $this->normalizedRows($rows);
        for ($index = 1; $index < count($rows); $index++) {
            $current = $rows[$index];
            $previous = $rows[$index - 1];
            $expected = (new DateTimeImmutable($current['date'] . ' 00:00:00', new DateTimeZone('UTC')))
                ->modify('first day of previous month')->format('Y-m');
            if (substr($previous['date'], 0, 7) === $expected && $previous['value'] != 0.0) {
                $this->add($metricId, $current['date'], ($current['value'] / $previous['value'] - 1.0) * 100.0, $quality, $revision);
            }
        }
    }

    private function normalizedRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $date = $row['date'] ?? $this->periodToDate((string) ($row['period'] ?? ''));
            $value = $this->number($row['value'] ?? null);
            if ($date !== null && $value !== null) {
                $normalized[$date] = ['date' => $date, 'value' => $value];
            }
        }
        ksort($normalized);
        return array_values($normalized);
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if (!is_numeric($normalized)) {
            return null;
        }
        $number = (float) $normalized;
        return is_finite($number) ? $number : null;
    }

    private function periodToDate(string $period): ?string
    {
        $period = trim($period);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $period, $match) === 1) {
            return $match[1] . '-' . $match[2] . '-' . $match[3];
        }
        if (preg_match('/^(\d{4})-Q([1-4])$/i', $period, $match) === 1) {
            return $match[1] . '-' . str_pad((string) (((int) $match[2] - 1) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01';
        }
        if (preg_match('/^Q([1-4])[- ](\d{4})$/i', $period, $match) === 1) {
            return $match[2] . '-' . str_pad((string) (((int) $match[1] - 1) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01';
        }
        if (preg_match('/^(\d{4})m(\d{1,2})$/i', $period, $match) === 1
            || preg_match('/^(\d{4})[- .](\d{2})$/', $period, $match) === 1) {
            return $match[1] . '-' . str_pad($match[2], 2, '0', STR_PAD_LEFT) . '-01';
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $period, $match) === 1) {
            return $match[1] . '-' . $match[2] . '-' . $match[3];
        }
        if (preg_match('/^(\d{4})(\d{2})$/', $period, $match) === 1) {
            return $match[1] . '-' . $match[2] . '-01';
        }
        return preg_match('/^\d{4}$/', $period) === 1 ? $period . '-01-01' : null;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $match) === 1) {
            return $match[3] . '-' . $match[1] . '-' . $match[2];
        }
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $match) === 1) {
            return sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]);
        }
        if (preg_match('/^(\d{2}) ([A-Za-z]{3}) (\d{4})$/', $value, $match) === 1) {
            $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
                'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
            if (isset($months[$match[2]])) {
                return sprintf('%04d-%02d-%02d', $match[3], $months[$match[2]], $match[1]);
            }
        }
        if (preg_match('/^(\d{2}) ([A-Za-z]{3}) (\d{2})$/', $value, $match) === 1) {
            $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
                'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
            if (isset($months[$match[2]])) {
                $year = (int) $match[3];
                $year += $year <= 68 ? 2000 : 1900;
                return sprintf('%04d-%02d-%02d', $year, $months[$match[2]], $match[1]);
            }
        }
        return $this->periodToDate($value);
    }

    private function isIsoDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function revision(string $prefix): string
    {
        return $prefix . '-' . gmdate('Y-m-d');
    }

    private function errorCode(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, 'http 429') => 'RATE_LIMITED',
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => 'SOURCE_TIMEOUT',
            str_contains($message, 'json') => 'INVALID_JSON',
            str_contains($message, 'column'), str_contains($message, 'spalte') => 'SOURCE_SCHEMA_CHANGED',
            str_contains($message, 'empty'), str_contains($message, 'keine') => 'EMPTY_SOURCE',
            default => 'SOURCE_FAILED',
        };
    }

    private function fetchDbnomics(string $provider, string $dataset, string $series): array
    {
        $url = sprintf(
            'https://api.db.nomics.world/v22/series/%s/%s/%s?observations=1',
            rawurlencode($provider),
            rawurlencode($dataset),
            rawurlencode($series),
        );
        $json = $this->http->getJson($url);
        $document = $json['series']['docs'][0] ?? null;
        if (!is_array($document)) {
            throw new RuntimeException('DBnomics series empty.');
        }
        $periods = is_array($document['period'] ?? null) ? $document['period'] : [];
        $values = is_array($document['value'] ?? null) ? $document['value'] : [];
        $rows = [];
        foreach ($periods as $index => $period) {
            $rows[] = ['period' => (string) $period, 'value' => $values[$index] ?? null];
        }
        return $rows;
    }

    private function fetchEcbPolicyRate(): array
    {
        try {
            return [
                'rows' => $this->fetchEcbPolicyRateDirect(),
                'source' => 'ecb-api',
                'revision' => 'ECB-FM-MRR',
            ];
        } catch (\Throwable $csvError) {
            try {
                return [
                    'rows' => $this->fetchEcbPolicyRateJson(),
                    'source' => 'ecb-api',
                    'revision' => 'ECB-FM-MRR-JSON-fallback',
                ];
            } catch (\Throwable $jsonError) {
                try {
                    $rows = $this->fetchDbnomics('ECB', 'FM', 'D.U2.EUR.4F.KR.MRR_RT.LEV');
                    if ($rows === []) {
                        throw new RuntimeException('ECB DBnomics fallback empty.');
                    }
                    return [
                        'rows' => $rows,
                        'source' => 'ecb-dbnomics',
                        'revision' => 'ECB-FM-MRR-DBnomics-fallback',
                    ];
                } catch (\Throwable $dbnomicsError) {
                    throw new RuntimeException(
                        'ECB CSV, JSON and DBnomics failed: '
                        . $csvError->getMessage() . '; '
                        . $jsonError->getMessage() . '; '
                        . $dbnomicsError->getMessage(),
                        0,
                        $dbnomicsError,
                    );
                }
            }
        }
    }

    private function fetchEcbPolicyRateDirect(): array
    {
        $url = 'https://data-api.ecb.europa.eu/service/data/FM/D.U2.EUR.4F.KR.MRR_RT.LEV?'
            . http_build_query([
                'startPeriod' => $this->from->format('Y-m-d'),
                'endPeriod' => $this->to->format('Y-m-d'),
                'format' => 'csvdata',
                'detail' => 'dataonly',
            ], '', '&', PHP_QUERY_RFC3986);
        $payload = $this->http->getText($url, ['Accept' => 'text/csv,*/*']);
        if (trim($payload) === '') {
            throw new RuntimeException('ECB policy-rate CSV empty.');
        }

        foreach ([',', ';'] as $delimiter) {
            $rows = $this->parseCsv($payload, $delimiter);
            foreach ($rows as $headerIndex => $headerRow) {
                $headers = array_map(
                    static fn ($header): string => trim((string) $header, "\xEF\xBB\xBF \t\r\n"),
                    $headerRow,
                );
                $dateIndex = array_search('TIME_PERIOD', $headers, true);
                $valueIndex = array_search('OBS_VALUE', $headers, true);
                if ($dateIndex === false || $valueIndex === false) {
                    continue;
                }

                $result = [];
                foreach (array_slice($rows, $headerIndex + 1) as $row) {
                    $result[] = [
                        'period' => (string) ($row[$dateIndex] ?? ''),
                        'value' => $row[$valueIndex] ?? null,
                    ];
                }
                if ($result !== []) {
                    return $result;
                }
            }
        }

        throw new RuntimeException('ECB policy-rate CSV columns missing.');
    }

    private function fetchEcbPolicyRateJson(): array
    {
        $url = 'https://data-api.ecb.europa.eu/service/data/FM/D.U2.EUR.4F.KR.MRR_RT.LEV?'
            . http_build_query([
                'startPeriod' => $this->from->format('Y-m-d'),
                'endPeriod' => $this->to->format('Y-m-d'),
                'format' => 'jsondata',
                'detail' => 'dataonly',
            ], '', '&', PHP_QUERY_RFC3986);
        $json = $this->http->getJson($url);
        $timeValues = $json['structure']['dimensions']['observation'][0]['values'] ?? null;
        $seriesCollection = $json['dataSets'][0]['series'] ?? null;
        if (!is_array($timeValues) || !is_array($seriesCollection) || $seriesCollection === []) {
            throw new RuntimeException('ECB policy-rate JSON dimensions missing.');
        }

        $series = reset($seriesCollection);
        $observations = is_array($series) ? ($series['observations'] ?? null) : null;
        if (!is_array($observations)) {
            throw new RuntimeException('ECB policy-rate JSON observations missing.');
        }

        $result = [];
        foreach ($timeValues as $index => $timeValue) {
            $period = is_array($timeValue) ? (string) ($timeValue['id'] ?? '') : '';
            $observation = $observations[(string) $index] ?? $observations[$index] ?? null;
            $value = is_array($observation) ? ($observation[0] ?? null) : null;
            if ($this->parseDate($period) !== null && $this->number($value) !== null) {
                $result[] = ['period' => $period, 'value' => $value];
            }
        }
        if ($result === []) {
            throw new RuntimeException('ECB policy-rate JSON observations empty.');
        }
        return $result;
    }

    private function fetchEurostat(string $dataset, array $parameters): array
    {
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $url = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/' . rawurlencode($dataset) . '?' . $query;
        $json = $this->http->getJson($url);
        $index = $json['dimension']['time']['category']['index'] ?? [];
        if (!is_array($index)) {
            throw new RuntimeException('Eurostat time dimension empty.');
        }
        asort($index, SORT_NUMERIC);
        $values = is_array($json['value'] ?? null) ? $json['value'] : [];
        $rows = [];
        $position = 0;
        foreach (array_keys($index) as $period) {
            $rows[] = ['period' => (string) $period, 'value' => $values[(string) $position] ?? $values[$position] ?? null];
            $position++;
        }
        return $rows;
    }

    private function fetchOecdCsv(string $dataflow, string $seriesKey, string $startPeriod, string $endPeriod): array
    {
        $url = 'https://sdmx.oecd.org/public/rest/v1/data/' . $dataflow . '/' . $seriesKey
            . '?startPeriod=' . rawurlencode($startPeriod) . '&endPeriod=' . rawurlencode($endPeriod);
        $rows = $this->parseCsv($this->http->getText($url, ['Accept' => 'text/csv,*/*']));
        if ($rows === []) {
            throw new RuntimeException('OECD CSV empty.');
        }
        $headers = array_map('trim', array_shift($rows));
        $period = array_search('TIME_PERIOD', $headers, true);
        $value = array_search('OBS_VALUE', $headers, true);
        $status = array_search('OBS_STATUS', $headers, true);
        $area = array_search('REF_AREA', $headers, true);
        if ($period === false || $value === false) {
            throw new RuntimeException('OECD CSV columns missing.');
        }
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'period' => (string) ($row[$period] ?? ''),
                'value' => $row[$value] ?? null,
                'status' => $status === false ? '' : (string) ($row[$status] ?? ''),
                'area' => $area === false ? '' : (string) ($row[$area] ?? ''),
            ];
        }
        return $result;
    }

    private function fetchFred(string $seriesId): array
    {
        $series = $this->fetchFredMany([$seriesId]);
        return $series[$seriesId] ?? [];
    }

    private function fetchFredMany(array $seriesIds): array
    {
        $seriesIds = array_values(array_unique(array_filter($seriesIds, static fn ($id): bool =>
            is_string($id) && preg_match('/^[A-Z0-9_-]{1,64}$/', $id) === 1)));
        if ($seriesIds === []) {
            throw new RuntimeException('FRED series identifiers missing.');
        }
        $url = 'https://fred.stlouisfed.org/graph/fredgraph.csv?id=' . implode(',', array_map('rawurlencode', $seriesIds))
            . '&cosd=' . rawurlencode($this->from->modify('-13 months')->format('Y-m-d'))
            . '&coed=' . rawurlencode($this->to->format('Y-m-d'));
        $rows = $this->parseCsv($this->http->getText($url, [
            'Accept' => 'text/csv,*/*',
            'User-Agent' => 'curl/8.0',
        ]));
        if ($rows === []) {
            throw new RuntimeException('FRED CSV empty.');
        }
        $headers = array_map(static fn ($header): string => trim((string) $header, "\xEF\xBB\xBF \t\r\n"), array_shift($rows));
        $dateIndex = array_search('observation_date', $headers, true);
        if ($dateIndex === false) {
            throw new RuntimeException('FRED CSV columns missing.');
        }
        $valueIndices = [];
        foreach ($seriesIds as $seriesId) {
            $valueIndex = array_search($seriesId, $headers, true);
            if ($valueIndex === false) {
                throw new RuntimeException('FRED CSV column missing: ' . $seriesId);
            }
            $valueIndices[$seriesId] = $valueIndex;
        }
        $result = array_fill_keys($seriesIds, []);
        foreach ($rows as $row) {
            $date = $this->parseDate((string) ($row[$dateIndex] ?? ''));
            if ($date === null) {
                continue;
            }
            foreach ($valueIndices as $seriesId => $valueIndex) {
                $value = $this->number($row[$valueIndex] ?? null);
                if ($value !== null) {
                    $result[$seriesId][] = ['date' => $date, 'value' => $value];
                }
            }
        }
        return $result;
    }

    private function fetchYahoo(string $symbol, string $startDate): array
    {
        $period1 = (new DateTimeImmutable($startDate . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $period2 = $this->to->modify('+2 days')->setTime(0, 0)->getTimestamp();
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
            . '?period1=' . $period1 . '&period2=' . $period2 . '&interval=1d&events=history&includeAdjustedClose=true';
        $json = $this->http->getJson($url, ['Accept' => 'application/json']);
        $result = $json['chart']['result'][0] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException('Yahoo chart result empty.');
        }
        return $this->parseYahooResult($result);
    }

    private function fetchYahooBatch(array $symbols, string $range): array
    {
        $symbols = array_values(array_unique(array_filter(
            $symbols,
            static fn ($symbol): bool => is_string($symbol) && $symbol !== '',
        )));
        if ($symbols === []) {
            return [];
        }

        $url = 'https://query2.finance.yahoo.com/v7/finance/spark?'
            . http_build_query([
                'symbols' => implode(',', $symbols),
                'range' => $range,
                'interval' => '1d',
            ], '', '&', PHP_QUERY_RFC3986);
        $json = $this->http->getJson($url, ['Accept' => 'application/json']);
        $items = $json['spark']['result'] ?? null;
        if (!is_array($items)) {
            throw new RuntimeException('Yahoo spark result empty.');
        }

        $result = [];
        foreach ($items as $item) {
            $symbol = is_array($item) ? (string) ($item['symbol'] ?? '') : '';
            $response = is_array($item) ? ($item['response'][0] ?? null) : null;
            if ($symbol !== '' && is_array($response)) {
                $result[$symbol] = $this->parseYahooResult($response);
            }
        }
        return $result;
    }

    private function parseYahooResult(array $result): array
    {
        $timestamps = is_array($result['timestamp'] ?? null) ? $result['timestamp'] : [];
        $adjusted = $result['indicators']['adjclose'][0]['adjclose'] ?? null;
        $close = $result['indicators']['quote'][0]['close'] ?? null;
        $values = is_array($adjusted) ? $adjusted : (is_array($close) ? $close : []);
        $timezone = (string) ($result['meta']['exchangeTimezoneName'] ?? 'UTC');
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new DateTimeZone('UTC');
        }
        $rows = [];
        foreach ($timestamps as $index => $timestamp) {
            $value = $this->number($values[$index] ?? null);
            if ($value === null || !is_numeric($timestamp)) {
                continue;
            }
            $instant = (new DateTimeImmutable('@' . (int) $timestamp));
            $rows[] = [
                'date' => $instant->setTimezone($zone)->format('Y-m-d'),
                'value' => $value,
                'source_timestamp' => $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ];
        }
        return $rows;
    }

    private function fetchBls(array $seriesIds): array
    {
        $combined = [];
        foreach ($seriesIds as $seriesId) {
            $html = $this->http->getText(
                'https://data.bls.gov/timeseries/' . rawurlencode($seriesId) . '?years_option=all_years',
                ['Accept' => 'text/html,*/*'],
            );
            $rows = $this->parseBlsSeriesReport($html);
            if ($rows === []) {
                throw new RuntimeException('BLS Series Report empty for ' . $seriesId);
            }
            $combined[$seriesId] = $rows;
            usleep(80_000);
        }
        return $combined;
    }

    private function fetchTradingEconomics(string $pagePath): array
    {
        $html = $this->http->getText('https://tradingeconomics.com/' . ltrim($pagePath, '/'), ['Accept' => 'text/html,*/*']);
        $symbol = $this->extractSetting($html, 'TESymbol');
        $dataSource = $this->extractSetting($html, 'TEChartsDatasource');
        $token = $this->extractSetting($html, 'TEChartsToken');
        $obfuscationKey = $this->extractSetting($html, 'TEObfuscationkey');
        $lastUpdate = $this->extractSetting($html, 'TELastUpdate');
        if ($symbol === null || $dataSource === null || $token === null || $obfuscationKey === null) {
            throw new RuntimeException('Trading Economics chart settings missing.');
        }
        if ($obfuscationKey !== 'tradingeconomics-charts-core-api-key') {
            throw new RuntimeException('Trading Economics chart schema changed.');
        }

        $query = [
            'd1' => $this->from->modify('-2 months')->format('Y-m-d'),
            'd2' => $this->to->format('Y-m-d'),
        ];
        if ($lastUpdate !== null) {
            $query['v'] = $lastUpdate . '00';
        }
        $url = rtrim($dataSource, '/') . '/economics/' . rawurlencode(strtolower($symbol)) . '?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $payload = $this->http->getJson($url, [
            'Accept' => 'application/json',
            'x-api-key' => $token,
        ]);
        $decoded = $this->decodeTradingEconomics($payload);
        return $this->parseTradingEconomicsRows($decoded, $symbol);
    }

    private function parseCsv(string $text, string $delimiter = ','): array
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Temporärer CSV-Speicher nicht verfügbar.');
        }
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (array_filter($row, static fn ($cell): bool => trim((string) $cell) !== '') !== []) {
                $rows[] = $row;
            }
        }
        fclose($handle);
        return $rows;
    }

    private function parseBlsSeriesReport(string $html): array
    {
        $rows = [];
        preg_match_all('/<tr\b[^>]*>\s*<th\b[^>]*>\s*(\d{4})\s*<\/th>([\s\S]*?)<\/tr>/i', $html, $years, PREG_SET_ORDER);
        foreach ($years as $yearRow) {
            preg_match_all('/<td\b[^>]*>([\s\S]*?)<\/td>/i', $yearRow[2], $cells);
            for ($month = 0; $month < 12; $month++) {
                $value = $this->number($this->decodeHtml((string) ($cells[1][$month] ?? '')));
                if ($value !== null) {
                    $rows[] = [
                        'date' => sprintf('%04d-%02d-01', $yearRow[1], $month + 1),
                        'value' => $value,
                    ];
                }
            }
        }
        return $rows;
    }

    private function extractSetting(string $html, string $name): ?string
    {
        preg_match_all('/(?:var\s+)?' . preg_quote($name, '/') . '\s*=\s*[\'\"]([^\'\"]*)[\'\"]/i', $html, $matches);
        $values = array_values(array_filter($matches[1] ?? [], static fn ($value): bool => $value !== ''));
        return $values === [] ? null : (string) end($values);
    }

    private function decodeTradingEconomics(mixed $payload): array
    {
        if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
            return $payload;
        }
        $encoded = is_string($payload) ? $payload : null;
        if (is_array($payload)) {
            foreach ($payload as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $encoded = $candidate;
                    break;
                }
            }
        }
        if ($encoded === null) {
            throw new RuntimeException('Trading Economics payload empty.');
        }
        $compressed = base64_decode($encoded, true);
        if ($compressed === false) {
            throw new RuntimeException('Trading Economics payload encoding invalid.');
        }
        $key = 'tradingeconomics-charts-core-api-key';
        for ($index = 0, $length = strlen($compressed); $index < $length; $index++) {
            $compressed[$index] = $compressed[$index] ^ $key[$index % strlen($key)];
        }
        $json = @gzdecode($compressed);
        if ($json === false) {
            $json = @gzinflate($compressed);
        }
        if (!is_string($json)) {
            throw new RuntimeException('Trading Economics payload compression invalid.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Trading Economics payload JSON invalid.');
        }
        return $decoded;
    }

    private function parseTradingEconomicsRows(array $payload, string $symbol): array
    {
        $series = $payload[0]['series'] ?? [];
        if (!is_array($series) || $series === []) {
            throw new RuntimeException('Trading Economics series empty.');
        }
        $normalized = strtolower((string) preg_replace('/:te$/i', '', $symbol));
        $selected = null;
        foreach ($series as $candidate) {
            $candidateSymbol = strtolower((string) preg_replace('/:te$/i', '', (string) ($candidate['serie']['s'] ?? '')));
            if ($candidateSymbol === $normalized && is_array($candidate['serie']['data'] ?? null)) {
                $selected = $candidate;
                break;
            }
        }
        $selected ??= $series[0];
        $points = $selected['serie']['data'] ?? [];
        if (!is_array($points)) {
            throw new RuntimeException('Trading Economics data array empty.');
        }
        $rows = [];
        foreach ($points as $point) {
            if (is_array($point) && array_is_list($point)) {
                $value = $point[0] ?? null;
                $date = $this->periodToDate((string) ($point[3] ?? ''));
                $timestamp = $point[1] ?? null;
            } else {
                $value = is_array($point) ? ($point['y'] ?? $point['value'] ?? null) : null;
                $date = is_array($point) ? $this->periodToDate((string) ($point['date'] ?? '')) : null;
                $timestamp = is_array($point) ? ($point['timestamp'] ?? null) : null;
            }
            if ($date === null && is_numeric($timestamp)) {
                $date = (new DateTimeImmutable('@' . (int) $timestamp))->format('Y-m-d');
            }
            if ($date !== null && $this->number($value) !== null) {
                $rows[] = ['date' => $date, 'value' => $value];
            }
        }
        return $rows;
    }

    private function decodeHtml(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function fetchBoj(string $database, string $code, string $startMonth): array
    {
        $url = 'https://www.stat-search.boj.or.jp/api/v1/getDataCode?format=json&lang=en&db=' . rawurlencode($database)
            . '&startDate=' . rawurlencode(str_replace('-', '', $startMonth))
            . '&endDate=' . $this->to->format('Ym') . '&code=' . rawurlencode($code);
        $json = $this->http->getJson($url);
        $item = $json['RESULTSET'][0] ?? null;
        if (!is_array($item) || !isset($item['VALUES'])) {
            throw new RuntimeException('BOJ series empty.');
        }
        $dates = is_array($item['VALUES']['SURVEY_DATES'] ?? null) ? $item['VALUES']['SURVEY_DATES'] : [];
        $values = is_array($item['VALUES']['VALUES'] ?? null) ? $item['VALUES']['VALUES'] : [];
        $rows = [];
        foreach ($dates as $index => $date) {
            $rows[] = ['date' => $this->parseDate((string) $date), 'value' => $values[$index] ?? null];
        }
        return $rows;
    }

    private function fetchCfets(string $startDate, string $endDate): array
    {
        $url = 'https://www.chinamoney.com.cn/ags/ms/cm-u-bk-currency/LprHis?lang=CN&strStartDate='
            . rawurlencode($startDate) . '&strEndDate=' . rawurlencode($endDate);
        $json = $this->http->postJson($url, [
            'Accept' => 'application/json,text/plain,*/*',
            'Content-Type' => 'application/json',
            'Origin' => 'https://www.chinamoney.com.cn',
            'Referer' => 'https://www.chinamoney.com.cn/',
        ], '{}');
        $records = is_array($json['records'] ?? null) ? $json['records'] : [];
        $rows = [];
        foreach ($records as $record) {
            $date = $this->parseDate((string) ($record['showDateCN'] ?? ''));
            $value = $record['1Y'] ?? null;
            if ($date !== null && $value !== '---' && $this->number($value) !== null) {
                $rows[] = ['date' => $date, 'value' => $value];
            }
        }
        return $rows;
    }

    private function fetchChinaBond(string $startDate, string $endDate): array
    {
        $url = 'https://yield.chinabond.com.cn/cbweb-czb-web/czb/historyQuery?startDate=' . rawurlencode($startDate)
            . '&endDate=' . rawurlencode($endDate) . '&gjqx=10&locale=en_US&qxmc=1';
        $json = $this->http->postJson($url, [
            'Accept' => 'application/json,text/plain,*/*',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Origin' => 'https://yield.chinabond.com.cn',
            'Referer' => 'https://yield.chinabond.com.cn/cbweb-czb-web/czb/showHistory?locale=en_US',
        ], '');
        $records = is_array($json['heList'] ?? null) ? $json['heList'] : [];
        $rows = [];
        foreach ($records as $record) {
            $date = $this->parseDate((string) ($record['workTime'] ?? ''));
            $value = $record['tenYear'] ?? null;
            if ($date !== null && $this->number($value) !== null) {
                $rows[] = ['date' => $date, 'value' => $value];
            }
        }
        return $rows;
    }

    private function fetchTreasury(array $years): array
    {
        $columns = [
            '1 Mo' => 'us_1m', '3 Mo' => 'us_3m', '2 Yr' => 'us_2y',
            '5 Yr' => 'us_5y', '10 Yr' => 'us_10y', '30 Yr' => 'us_30y',
        ];
        $result = array_fill_keys(array_values($columns), []);
        foreach ($years as $year) {
            $url = 'https://home.treasury.gov/resource-center/data-chart-center/interest-rates/daily-treasury-rates.csv/'
                . $year . '/all?type=daily_treasury_yield_curve&field_tdr_date_value=' . $year . '&page&_format=csv';
            $rows = $this->parseCsv($this->http->getText($url, ['Accept' => 'text/csv,*/*']));
            if ($rows === []) {
                continue;
            }
            $headers = array_map('trim', array_shift($rows));
            $dateIndex = array_search('Date', $headers, true);
            if ($dateIndex === false) {
                throw new RuntimeException('Treasury CSV Date column missing.');
            }
            foreach ($rows as $row) {
                $date = $this->parseDate((string) ($row[$dateIndex] ?? ''));
                if ($date === null) {
                    continue;
                }
                foreach ($columns as $column => $metricId) {
                    $index = array_search($column, $headers, true);
                    $value = $index === false ? null : $this->number($row[$index] ?? null);
                    if ($value !== null) {
                        $result[$metricId][] = ['date' => $date, 'value' => $value];
                    }
                }
            }
        }
        return $result;
    }

    private function fetchBundesbank(string $series): array
    {
        $parts = explode('.', $series, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Bundesbank series identifier invalid.');
        }
        $url = 'https://api.statistiken.bundesbank.de/rest/data/' . rawurlencode($parts[0]) . '/' . $parts[1]
            . '?startPeriod=' . rawurlencode($this->from->format('Y-m-d'));
        $rows = $this->parseCsv($this->http->getText($url, ['Accept' => 'text/csv']), ';');
        if ($rows === []) {
            throw new RuntimeException('Bundesbank CSV empty.');
        }
        $headers = array_map('trim', array_shift($rows));
        $dateIndex = array_search('TIME_PERIOD', $headers, true);
        $valueIndex = array_search('OBS_VALUE', $headers, true);
        if ($dateIndex === false || $valueIndex === false) {
            throw new RuntimeException('Bundesbank CSV columns missing.');
        }
        $result = [];
        foreach ($rows as $row) {
            $date = $this->parseDate((string) ($row[$dateIndex] ?? ''));
            $value = $this->number($row[$valueIndex] ?? null);
            if ($date !== null && $value !== null) {
                $result[] = ['date' => $date, 'value' => $value];
            }
        }
        return $result;
    }

    private function fetchBoeRate(): array
    {
        $errors = [];
        foreach ([
            ['method' => 'fetchBoeRateCsv', 'revision' => 'BoE-IUDBEDR-CSV'],
            ['method' => 'fetchBoeRateHtml', 'revision' => 'BoE-IUDBEDR-HTML-fallback'],
            ['method' => 'fetchBoeCurrentRate', 'revision' => 'BoE-current-rate-fallback'],
        ] as $attempt) {
            try {
                $rows = $this->{$attempt['method']}();
                if ($rows === []) {
                    throw new RuntimeException('Bank of England response empty.');
                }
                return [
                    'rows' => $rows,
                    'revision' => $attempt['revision'],
                ];
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
        throw new RuntimeException('Bank of England fallbacks failed: ' . implode('; ', $errors));
    }

    private function fetchBoeRateCsv(): array
    {
        $url = 'https://www.bankofengland.co.uk/boeapps/database/_iadb-fromshowcolumns.asp?csv.x=yes'
            . '&Datefrom=' . rawurlencode($this->from->format('d/M/Y'))
            . '&Dateto=' . rawurlencode($this->to->format('d/M/Y'))
            . '&SeriesCodes=IUDBEDR&CSVF=TN&UsingCodes=Y&VPD=Y&VFD=N';
        $payload = $this->http->getText($url, ['Accept' => 'text/csv,*/*']);
        if (trim($payload) === '') {
            throw new RuntimeException('Bank of England CSV empty.');
        }

        foreach ([',', ';'] as $delimiter) {
            $rows = $this->parseCsv($payload, $delimiter);
            foreach ($rows as $headerIndex => $headerRow) {
                $headers = array_map(
                    static fn ($header): string => trim((string) $header, "\xEF\xBB\xBF \t\r\n"),
                    $headerRow,
                );
                $dateIndex = array_search('DATE', $headers, true);
                $valueIndex = array_search('IUDBEDR', $headers, true);
                if ($dateIndex === false || $valueIndex === false) {
                    continue;
                }

                $result = [];
                foreach (array_slice($rows, $headerIndex + 1) as $row) {
                    $date = $this->parseDate((string) ($row[$dateIndex] ?? ''));
                    $value = $this->number($row[$valueIndex] ?? null);
                    if ($date !== null && $value !== null) {
                        $result[] = ['date' => $date, 'value' => $value];
                    }
                }
                if ($result !== []) {
                    return $result;
                }
            }
        }

        throw new RuntimeException('Bank of England CSV columns missing.');
    }

    private function fetchBoeRateHtml(): array
    {
        $url = 'https://www.bankofengland.co.uk/boeapps/database/fromshowcolumns.asp?'
            . http_build_query([
                'CSVF' => 'TT',
                'DAT' => 'RNG',
                'FD' => $this->from->format('j'),
                'FM' => $this->from->format('M'),
                'FNY' => 'Y',
                'FY' => $this->from->format('Y'),
                'Filter' => 'N',
                'FromSeries' => '1',
                'SeriesCodes' => 'IUDBEDR',
                'TD' => $this->to->format('j'),
                'TM' => $this->to->format('M'),
                'TY' => $this->to->format('Y'),
                'ToSeries' => '50',
                'Travel' => 'NIxAZxSUx',
                'UsingCodes' => 'Y',
                'VPD' => 'Y',
                'VFD' => 'N',
                'html.x' => '66',
                'html.y' => '26',
                'title' => 'IUDBEDR',
            ], '', '&', PHP_QUERY_RFC3986);
        $html = $this->http->getText($url, ['Accept' => 'text/html,*/*']);
        if (!str_contains($html, 'IUDBEDR')) {
            throw new RuntimeException('Bank of England HTML series column missing.');
        }

        preg_match_all(
            '/<tr\b[^>]*>\s*<td\b[^>]*>([\s\S]*?)<\/td>\s*<td\b[^>]*>([\s\S]*?)<\/td>\s*<\/tr>/i',
            $html,
            $matches,
            PREG_SET_ORDER,
        );
        $result = [];
        foreach ($matches as $match) {
            $date = $this->parseDate($this->decodeHtml((string) ($match[1] ?? '')));
            $value = $this->number($this->decodeHtml((string) ($match[2] ?? '')));
            if ($date !== null && $value !== null) {
                $result[] = ['date' => $date, 'value' => $value];
            }
        }
        if ($result === []) {
            throw new RuntimeException('Bank of England HTML observations empty.');
        }
        return $result;
    }

    private function fetchBoeCurrentRate(): array
    {
        $url = 'https://www.bankofengland.co.uk/boeapps/database/Bank-Rate.asp';
        $html = $this->http->getText($url, ['Accept' => 'text/html,*/*']);
        if (preg_match('/<p\b[^>]*class=["\'][^"\']*stat-figure[^"\']*["\'][^>]*>([\s\S]*?)<\/p>/i', $html, $match) !== 1) {
            throw new RuntimeException('Bank of England current-rate marker missing.');
        }
        $text = rtrim($this->decodeHtml((string) ($match[1] ?? '')), "% \t\r\n");
        $value = $this->number($text);
        if ($value === null || $value < 0.0) {
            throw new RuntimeException('Bank of England current-rate value missing.');
        }
        return [['date' => $this->to->format('Y-m-d'), 'value' => $value]];
    }

    private function fetchMofJgb(): array
    {
        $url = 'https://www.mof.go.jp/english/policy/jgbs/reference/interest_rate/historical/jgbcme_all.csv';
        $rows = $this->parseCsv($this->http->getText($url, ['Accept' => 'text/csv,*/*']));
        $headerIndex = null;
        foreach ($rows as $index => $row) {
            if (in_array('Date', array_map('trim', $row), true)) {
                $headerIndex = $index;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new RuntimeException('JGB CSV Date header missing.');
        }
        $headers = array_map('trim', $rows[$headerIndex]);
        $dateIndex = array_search('Date', $headers, true);
        $valueIndex = array_search('10Y', $headers, true);
        if ($dateIndex === false || $valueIndex === false) {
            throw new RuntimeException('JGB CSV columns missing.');
        }
        $result = [];
        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $result[] = [
                'date' => $this->parseDate((string) ($row[$dateIndex] ?? '')),
                'value' => $row[$valueIndex] ?? null,
            ];
        }
        return $result;
    }

    private function parseStoxx(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $cells = array_map('trim', explode(';', ltrim($line, "\xEF\xBB\xBF")));
            if (count($cells) < 3 || strtoupper($cells[1]) !== 'V2TX') {
                continue;
            }
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $cells[0], $match) !== 1) {
                continue;
            }
            $rows[] = ['date' => $match[3] . '-' . $match[2] . '-' . $match[1], 'value' => $cells[2]];
        }
        return $rows;
    }

    private function parseMultpl(string $html): array
    {
        $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
        preg_match_all('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', $html, $matches);
        $rows = [];
        foreach ($matches[0] ?? [] as $htmlRow) {
            preg_match_all('/<td\b[^>]*>([\s\S]*?)<\/td>/i', $htmlRow, $cellMatches);
            $cells = array_map([$this, 'decodeHtml'], $cellMatches[1] ?? []);
            if (count($cells) < 2 || preg_match('/†|estimate/i', implode(' ', $cells)) === 1) {
                continue;
            }
            if (preg_match('/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2}),\s+(\d{4})$/', $cells[0], $date) !== 1) {
                continue;
            }
            $value = $this->number(str_replace([',', '%', 'x'], '', $cells[1]));
            if ($value !== null) {
                $rows[] = ['date' => sprintf('%04d-%02d-%02d', $date[3], $months[$date[1]], $date[2]), 'value' => $value];
            }
        }
        return $rows;
    }

    private function parseLense(string $html): array
    {
        preg_match_all('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', $html, $matches);
        $rows = [];
        foreach ($matches[0] ?? [] as $htmlRow) {
            preg_match_all('/<td\b[^>]*>([\s\S]*?)<\/td>/i', $htmlRow, $cellMatches);
            $cells = array_map([$this, 'decodeHtml'], $cellMatches[1] ?? []);
            if (count($cells) < 2 || preg_match('/^\d{4}-\d{2}$/', $cells[0]) !== 1) {
                continue;
            }
            $value = $this->number((string) preg_replace('/x$/i', '', $cells[1]));
            if ($value !== null) {
                $rows[] = ['date' => $cells[0] . '-01', 'value' => $value];
            }
        }
        return $rows;
    }

    private function zipEntries(string $bytes, ?callable $filter = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP extension missing.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'weltmarkt-zip-');
        if ($temporary === false || file_put_contents($temporary, $bytes) === false) {
            throw new RuntimeException('ZIP temporary file unavailable.');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($temporary) !== true) {
                throw new RuntimeException('ZIP archive invalid.');
            }
            $opened = true;
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || ($filter !== null && !$filter($name))) {
                    continue;
                }
                $contents = $zip->getFromIndex($index);
                if (is_string($contents)) {
                    $entries[$name] = $contents;
                }
            }
            return $entries;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($temporary);
        }
    }

    private function fetchCensusProgram(string $program, array $categories): array
    {
        $bytes = $this->http->getText('https://www.census.gov/econ_getzippedfile/?programCode=' . rawurlencode($program));
        $entries = $this->zipEntries($bytes, static fn (string $name): bool => str_ends_with(strtolower($name), '-mf.csv'));
        if ($entries === []) {
            throw new RuntimeException('Census ZIP data file missing.');
        }
        return $this->parseCensusLong((string) reset($entries), $categories);
    }

    private function parseCensusLong(string $text, array $categories): array
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Census temporary stream unavailable.');
        }
        fwrite($handle, $text);
        rewind($handle);

        $periods = [];
        $names = ['period' => 'per_idx', 'category' => 'cat_idx', 'data_type' => 'dt_idx',
            'error_type' => 'et_idx', 'geography' => 'geo_idx', 'adjusted' => 'is_adj', 'value' => 'val'];
        $indices = null;
        $readingPeriods = false;
        $expectDataHeader = false;
        $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
        $earliest = $this->from->modify('-13 months')->format('Y-m-d');
        $latest = $this->to->modify('+1 day')->format('Y-m-d');
        $result = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $first = trim((string) ($row[0] ?? ''));
            if ($first === 'TIME PERIODS') {
                $readingPeriods = true;
                continue;
            }
            if ($first === 'DATA') {
                $readingPeriods = false;
                $expectDataHeader = true;
                continue;
            }
            if ($readingPeriods && preg_match('/^\d+$/', $first) === 1) {
                $periods[$first] = trim((string) ($row[1] ?? ''));
                continue;
            }
            if ($expectDataHeader) {
                $headers = array_map('trim', $row);
                $indices = [];
                foreach ($names as $key => $name) {
                    $position = array_search($name, $headers, true);
                    if ($position === false) {
                        fclose($handle);
                        throw new RuntimeException('Census CSV column missing: ' . $name);
                    }
                    $indices[$key] = $position;
                }
                $expectDataHeader = false;
                continue;
            }
            if ($indices === null) {
                continue;
            }
            if (in_array($first, ['NOTES', 'DATA UPDATED ON'], true)) {
                break;
            }
            $periodText = $periods[trim((string) ($row[$indices['period']] ?? ''))] ?? '';
            if (preg_match('/^([A-Za-z]{3})-(\d{4})$/', $periodText, $date) !== 1 || !isset($months[$date[1]])) {
                continue;
            }
            $isoDate = sprintf('%04d-%02d-01', $date[2], $months[$date[1]]);
            $category = trim((string) ($row[$indices['category']] ?? ''));
            if ($isoDate < $earliest || $isoDate > $latest
                || !in_array($category, $categories, true)
                || trim((string) ($row[$indices['data_type']] ?? '')) !== '1'
                || trim((string) ($row[$indices['error_type']] ?? '')) !== '0'
                || trim((string) ($row[$indices['geography']] ?? '')) !== '1'
                || trim((string) ($row[$indices['adjusted']] ?? '')) !== '1') {
                continue;
            }
            $result[] = [
                'date' => $isoDate,
                'category' => $category,
                'value' => $this->number($row[$indices['value']] ?? null),
            ];
        }

        fclose($handle);
        if ($indices === null) {
            throw new RuntimeException('Census long-file sections missing.');
        }
        return $result;
    }

    private function fetchChinaData(string $slug): array
    {
        $json = $this->http->getJson('https://chinadata.live/api/v2/data/' . rawurlencode($slug));
        $rows = $json['data']['data'] ?? [];
        if (!is_array($rows) || $rows === []) {
            throw new RuntimeException('China Data Portal series empty.');
        }
        return array_map(static fn (array $row): array => [
            'period' => (string) ($row['date'] ?? ''),
            'value' => $row['value'] ?? null,
        ], $rows);
    }

    private function fetchCpbWorkbook(): array
    {
        $months = ['january', 'february', 'march', 'april', 'may', 'june',
            'july', 'august', 'september', 'october', 'november', 'december'];
        $bytes = null;
        for ($offset = 2; $offset <= 10; $offset++) {
            $month = $this->to->modify('-' . $offset . ' months');
            $url = sprintf(
                'https://www.cpb.nl/system/files/cpbmedia/cpb-world-trade-monitor-%s-%s.xlsx',
                $months[(int) $month->format('n') - 1],
                $month->format('Y'),
            );
            try {
                $bytes = $this->http->getText($url, ['Accept' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                break;
            } catch (\Throwable) {
                continue;
            }
        }
        if (!is_string($bytes)) {
            throw new RuntimeException('CPB current workbook not found.');
        }
        $entries = $this->zipEntries($bytes, static fn (string $name): bool => in_array($name, [
            'xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml',
        ], true));
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $required) {
            if (!isset($entries[$required])) {
                throw new RuntimeException('CPB workbook worksheet missing.');
            }
        }
        $shared = $this->parseExcelSharedStrings($entries['xl/sharedStrings.xml'] ?? '');
        $trade = $this->parseExcelSheet($entries['xl/worksheets/sheet1.xml'], $shared);
        $industry = $this->parseExcelSheet($entries['xl/worksheets/sheet2.xml'], $shared);
        $header = null;
        foreach ($trade as $row) {
            foreach ($row as $value) {
                if ((string) $value === '2000m01') {
                    $header = $row;
                    break 2;
                }
            }
        }
        if ($header === null) {
            throw new RuntimeException('CPB monthly header missing.');
        }
        $dates = [];
        foreach ($header as $column => $value) {
            $date = $this->periodToDate((string) $value);
            if ($date !== null) {
                $dates[$column] = $date;
            }
        }
        $extract = function (array $sheet, string $code) use ($dates): array {
            foreach ($sheet as $row) {
                if (!in_array($code, array_map('trim', array_map('strval', $row)), true)) {
                    continue;
                }
                $result = [];
                foreach ($dates as $column => $date) {
                    $value = $this->number($row[$column] ?? null);
                    if ($value !== null) {
                        $result[] = ['date' => $date, 'value' => $value];
                    }
                }
                return $result;
            }
            throw new RuntimeException('CPB series missing: ' . $code);
        };
        return [
            'trade' => $extract($trade, 'tgz_w1_qnmi_sn'),
            'industry' => $extract($industry, 'ipz_w1_qnmi_sm'),
        ];
    }

    private function fetchImfCommodityWorkbook(): array
    {
        $bytes = $this->http->getText(
            'https://www.imf.org/-/media/files/research/commodityprices/monthly/external-data.xlsx',
            [
                'Accept' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'User-Agent' => 'curl/8.0',
            ],
        );
        $entries = $this->zipEntries($bytes, static fn (string $name): bool => in_array($name, [
            'xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml',
        ], true));
        if (!isset($entries['xl/worksheets/sheet1.xml'])) {
            throw new RuntimeException('IMF commodity worksheet missing.');
        }
        $shared = $this->parseExcelSharedStrings($entries['xl/sharedStrings.xml'] ?? '');
        $sheet = $this->parseExcelSheet($entries['xl/worksheets/sheet1.xml'], $shared);
        $header = $sheet[0] ?? null;
        if (!is_array($header)) {
            throw new RuntimeException('IMF commodity header missing.');
        }
        $definitions = [
            'PALLFNF' => 'world_commodity_index',
            'PNRG' => 'world_energy_index',
            'PFOOD' => 'world_food_index',
            'PMETA' => 'world_metals_index',
        ];
        $columns = [];
        foreach ($header as $column => $code) {
            if (isset($definitions[(string) $code])) {
                $columns[$column] = (string) $code;
            }
        }
        if (count($columns) !== count($definitions)) {
            throw new RuntimeException('IMF commodity columns missing.');
        }
        $result = array_fill_keys(array_keys($definitions), []);
        foreach (array_slice($sheet, 4) as $row) {
            $date = $this->periodToDate((string) ($row['A'] ?? ''));
            if ($date === null) {
                continue;
            }
            foreach ($columns as $column => $code) {
                $value = $this->number($row[$column] ?? null);
                if ($value !== null) {
                    $result[$code][] = ['date' => $date, 'value' => $value];
                }
            }
        }
        return $result;
    }

    private function fetchCommodityIndices(): array
    {
        try {
            return [
                'source' => 'imf-commodity-xlsx',
                'revision' => 'IMF-PCPS',
                'rows' => $this->fetchImfCommodityWorkbook(),
            ];
        } catch (\Throwable $imfError) {
            $fred = $this->fetchFredMany(['PALLFNFINDEXM', 'PNRGINDEXM', 'PFOODINDEXM', 'PMETAINDEXM']);
            return [
                'source' => 'fred-public-csv',
                'revision' => 'FRED-IMF-PCPS',
                'rows' => [
                    'PALLFNF' => $fred['PALLFNFINDEXM'] ?? [],
                    'PNRG' => $fred['PNRGINDEXM'] ?? [],
                    'PFOOD' => $fred['PFOODINDEXM'] ?? [],
                    'PMETA' => $fred['PMETAINDEXM'] ?? [],
                ],
            ];
        }
    }

    private function parseExcelSharedStrings(string $xml): array
    {
        preg_match_all('/<si\b[^>]*>([\s\S]*?)<\/si>/', $xml, $items);
        $strings = [];
        foreach ($items[1] ?? [] as $item) {
            preg_match_all('/<t\b[^>]*>([\s\S]*?)<\/t>/', $item, $texts);
            $strings[] = html_entity_decode(implode('', $texts[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return $strings;
    }

    private function parseExcelSheet(string $xml, array $sharedStrings): array
    {
        preg_match_all('/<row\b[^>]*\br="(\d+)"[^>]*>([\s\S]*?)<\/row>/', $xml, $rowMatches, PREG_SET_ORDER);
        $rows = [];
        foreach ($rowMatches as $rowMatch) {
            preg_match_all('/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/', $rowMatch[2], $cellMatches, PREG_SET_ORDER);
            $cells = [];
            foreach ($cellMatches as $cell) {
                if (preg_match('/\br="([A-Z]+)\d+"/i', $cell[1], $reference) !== 1) {
                    continue;
                }
                preg_match('/\bt="([^"]+)"/i', $cell[1], $typeMatch);
                $type = $typeMatch[1] ?? '';
                $body = $cell[2] ?? '';
                $value = '';
                if (preg_match('/<v\b[^>]*>([\s\S]*?)<\/v>/', $body, $valueMatch) === 1) {
                    $raw = html_entity_decode($valueMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $value = $type === 's' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                } elseif ($type === 'inlineStr') {
                    preg_match_all('/<t\b[^>]*>([\s\S]*?)<\/t>/', $body, $texts);
                    $value = html_entity_decode(implode('', $texts[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                $cells[strtoupper($reference[1])] = $value;
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    private function collectMarkets(): void
    {
        $stoxx = $this->safe('STOXX V2TX', fn (): string => $this->http->getText(
            'https://www.stoxx.com/document/Indices/Current/HistoricalData/h_v2tx.txt',
            ['Accept' => 'text/plain,*/*'],
        ));
        if (is_string($stoxx)) {
            $this->touch('stoxx-v2tx');
            $this->addPeriodRows('v2tx', $this->parseStoxx($stoxx), fn ($value) => $value, 'valid', $this->revision('STOXX-V2TX'));
        }

        $definitions = [
            ['msci_world', 'URTH', false], ['msci_em', 'EEM', false], ['sp500', '^GSPC', false],
            ['ndx', '^NDX', false], ['djia', '^DJI', false], ['stoxx50', '^STOXX50E', false],
            ['dax40', '^GDAXI', false], ['nikkei', '^N225', false], ['ssec', '000001.SS', false],
            ['brent', 'BZ=F', false], ['natgas', 'NG=F', false], ['gold', 'GC=F', false], ['copper', 'HG=F', false],
            ['sec_tech', 'XLK', true], ['sec_fin', 'XLF', true], ['sec_health', 'XLV', true],
            ['sec_energy', 'XLE', true], ['sec_cons', 'XLY', true], ['sec_mater', 'XLB', true], ['sec_util', 'XLU', true],
            ['vix', '^VIX', false], ['vxn', '^VXN', false], ['move', '^MOVE', false],
            ['eurusd', 'EURUSD=X', false], ['usdjpy', 'JPY=X', false], ['gbpusd', 'GBPUSD=X', false],
            ['usdcny', 'CNY=X', false], ['dxy', 'DX-Y.NYB', false],
        ];

        $sectorSymbols = array_column(array_values(array_filter(
            $definitions,
            static fn (array $definition): bool => $definition[2] === true,
        )), 1);
        $marketSymbols = array_column(array_values(array_filter(
            $definitions,
            static fn (array $definition): bool => $definition[2] === false,
        )), 1);
        $batchGroups = [
            [array_merge($sectorSymbols, array_slice($marketSymbols, 0, 13)), '2y'],
            [array_slice($marketSymbols, 13), '1mo'],
        ];

        $batched = [];
        foreach ($batchGroups as [$symbols, $range]) {
            try {
                $batched += $this->fetchYahooBatch($symbols, $range);
            } catch (\Throwable) {
                // The individual chart endpoint remains a zero-key fallback if Spark changes or is rate-limited.
            }
        }

        foreach ($definitions as [$metricId, $symbol, $sector]) {
            $startDate = $sector
                ? $this->to->modify('first day of January last year')->format('Y-m-d')
                : $this->from->format('Y-m-d');
            $result = $batched[$symbol] ?? null;
            if (is_array($result)) {
                $result = array_values(array_filter(
                    $result,
                    static fn (array $row): bool => (string) ($row['date'] ?? '') >= $startDate,
                ));
            } else {
                $result = $this->safe('Yahoo ' . $metricId, fn (): array => $this->fetchYahoo($symbol, $startDate));
            }
            if (!is_array($result)) {
                continue;
            }
            $this->touch($sector ? 'derived-metrics' : 'yahoo-chart');
            if (!$sector) {
                $this->addPeriodRows($metricId, $result, fn ($value) => $value, 'provisional', $this->revision('Yahoo-chart'));
                continue;
            }

            $rows = $this->normalizedRows($result);
            $currentYear = null;
            $base = null;
            $lastClose = null;
            foreach ($rows as $row) {
                $year = substr($row['date'], 0, 4);
                if ($year !== $currentYear) {
                    $currentYear = $year;
                    $base = $lastClose;
                }
                if ($base !== null && $base != 0.0) {
                    $this->add(
                        $metricId,
                        $row['date'],
                        ($row['value'] / $base - 1.0) * 100.0,
                        'provisional',
                        $this->revision('Yahoo-sector-YTD'),
                    );
                }
                $lastClose = $row['value'];
            }
        }
    }

    private function collectPolicyRates(): void
    {
        foreach ([
            ['fed_rate', 'FED', 'H15', 'RIFSPFF_N.D', 'fed-dbnomics'],
        ] as [$metricId, $provider, $dataset, $series, $source]) {
            $rows = $this->safe('DBnomics ' . $metricId, fn (): array => $this->fetchDbnomics($provider, $dataset, $series));
            if (is_array($rows)) {
                $this->touch($source);
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision($provider . '-' . $dataset));
            }
        }

        $ecb = $this->safe('ECB policy rate', fn (): array => $this->fetchEcbPolicyRate());
        if (is_array($ecb) && is_array($ecb['rows'] ?? null)) {
            $this->touch((string) $ecb['source']);
            $this->addPeriodRows(
                'ecb_rate',
                $ecb['rows'],
                fn ($value) => $value,
                'valid',
                $this->revision((string) $ecb['revision']),
            );
        }

        $boe = $this->safe('Bank of England rate', fn (): array => $this->fetchBoeRate());
        if (is_array($boe) && is_array($boe['rows'] ?? null)) {
            $this->touch('boe-api');
            $this->addPeriodRows(
                'boe_rate',
                $boe['rows'],
                fn ($value) => $value,
                'valid',
                $this->revision((string) $boe['revision']),
            );
        }
        $boj = $this->safe('Bank of Japan rate', fn (): array => $this->fetchBoj('FM01', 'STRDCLUCON', $this->from->format('Y-m')));
        if (is_array($boj)) {
            $this->touch('boj-api');
            $this->addPeriodRows('boj_rate', $boj, fn ($value) => $value, 'valid', $this->revision('BOJ-STRDCLUCON'));
        }
    }

    private function collectPbocRate(): void
    {
        $from = $this->to->modify('first day of -3 months')->format('Y-m-d');
        $pboc = $this->safe('PBoC CFETS LPR', fn (): array => $this->fetchCfets($from, $this->to->format('Y-m-d')));
        if (is_array($pboc)) {
            $this->touch('pboc-cfets');
            $this->addPeriodRows('pboc_rate', $pboc, fn ($value) => $value, 'valid', $this->revision('CFETS-LPR-1Y'));
        }
    }

    private function collectYields(): void
    {
        $years = array_values(array_unique([(int) $this->from->format('Y'), (int) $this->to->format('Y')]));
        $treasury = $this->safe('U.S. Treasury yield curve', fn (): array => $this->fetchTreasury($years));
        $fresh = [];
        if (is_array($treasury)) {
            $this->touch('treasury-api');
            foreach ($treasury as $metricId => $rows) {
                $fresh[$metricId] = $rows;
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision('Treasury-par-yield'));
            }
        }

        foreach ([
            ['de_2y', 'BBSSY.D.REN.EUR.A610.000000WT0202.A'],
            ['de_10y', 'BBSSY.D.REN.EUR.A630.000000WT1010.A'],
            ['de_30y', 'BBSSY.D.REN.EUR.A640.000000WT3030.A'],
        ] as [$metricId, $series]) {
            $rows = $this->safe('Bundesbank ' . $metricId, fn (): array => $this->fetchBundesbank($series));
            if (is_array($rows)) {
                $this->touch('bundesbank-api');
                $fresh[$metricId] = $rows;
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision('Bundesbank-API'));
            }
        }

        $jgb = $this->safe('Japan MOF JGB 10Y', fn (): array => $this->fetchMofJgb());
        if (is_array($jgb)) {
            $this->touch('mof-japan-csv');
            $this->addPeriodRows('jp_10y', $jgb, fn ($value) => $value, 'valid', $this->revision('Japan-MOF-JGB'));
        }
        $chinaBond = $this->safe('ChinaBond 10Y', fn (): array => $this->fetchChinaBond($this->from->format('Y-m-d'), $this->to->format('Y-m-d')));
        if (is_array($chinaBond)) {
            $this->touch('chinabond-yield');
            $this->addPeriodRows('cn_10y', $chinaBond, fn ($value) => $value, 'valid', $this->revision('ChinaBond-10Y'));
        }
        $oas = $this->safe('FRED high-yield OAS', fn (): array => $this->fetchFred('BAMLH0A0HYM2'));
        if (is_array($oas)) {
            $this->touch('fred-public-csv');
            $this->addPeriodRows('us_hy_oas', $oas, fn ($value) => $value, 'provisional', $this->revision('FRED-BAMLH0A0HYM2'));
        }

        foreach ([
            ['us_10y2y', 'us_10y', 'us_2y'],
            ['us_10y3m', 'us_10y', 'us_3m'],
            ['de_10y2y', 'de_10y', 'de_2y'],
        ] as [$metricId, $leftId, $rightId]) {
            $left = [];
            foreach ($this->normalizedRows($fresh[$leftId] ?? []) as $row) {
                $left[$row['date']] = $row['value'];
            }
            $right = [];
            foreach ($this->normalizedRows($fresh[$rightId] ?? []) as $row) {
                $right[$row['date']] = $row['value'];
            }
            foreach ($left as $date => $value) {
                if (isset($right[$date])) {
                    $this->add($metricId, $date, ($value - $right[$date]) * 100.0, 'valid', $this->revision('derived-same-day'));
                    $this->touch('derived-metrics');
                }
            }
        }
    }

    private function collectFedBalance(): void
    {
        $rows = $this->safe('Federal Reserve balance sheet', fn (): array => $this->fetchDbnomics('FED', 'H41', 'RESPPA_N.WW'));
        if (is_array($rows)) {
            $this->touch('fed-dbnomics');
            $this->addPeriodRows(
                'fed_bs',
                $rows,
                fn ($value) => ($number = $this->number($value)) === null ? null : $number / 1_000_000.0,
                'revised',
                $this->revision('FED-H41'),
            );
        }
    }

    private function collectMonthlyEarly(): void
    {
        foreach ([
            ['cn_pmi_mfg', 'M_A0B01', 'A0B0101'],
            ['cn_pmi_svc', 'M_A0B02', 'A0B0201'],
        ] as [$metricId, $dataset, $series]) {
            $rows = $this->safe('NBS ' . $metricId, fn (): array => $this->fetchDbnomics('NBS', $dataset, $series));
            if (is_array($rows)) {
                $this->touch('nbs-dbnomics');
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision('NBS-DBnomics'));
            }
        }

        $definitions = [
            ['us_pmi_mfg', 'united-states/business-confidence', 'ismworld-reports'],
            ['us_pmi_svc', 'united-states/non-manufacturing-pmi', 'ismworld-reports'],
            ['us_pmi_comp', 'united-states/composite-pmi', 'tradingeconomics-chart'],
            ['ez_pmi_mfg', 'euro-area/manufacturing-pmi', 'tradingeconomics-chart'],
            ['ez_pmi_svc', 'euro-area/services-pmi', 'tradingeconomics-chart'],
            ['ez_pmi_comp', 'euro-area/composite-pmi', 'tradingeconomics-chart'],
            ['jp_pmi_mfg', 'japan/manufacturing-pmi', 'tradingeconomics-chart'],
            ['jp_pmi_svc', 'japan/services-pmi', 'tradingeconomics-chart'],
            ['uk_pmi_mfg', 'united-kingdom/manufacturing-pmi', 'tradingeconomics-chart'],
            ['uk_pmi_svc', 'united-kingdom/services-pmi', 'tradingeconomics-chart'],
            ['us_umich', 'united-states/consumer-confidence', 'tradingeconomics-chart'],
            ['cn_unemp', 'china/unemployment-rate', 'tradingeconomics-chart'],
            ['jp_unemp', 'japan/unemployment-rate', 'tradingeconomics-chart'],
            ['uk_unemp', 'united-kingdom/unemployment-rate', 'tradingeconomics-chart'],
            ['uk_cpi', 'united-kingdom/inflation-cpi', 'tradingeconomics-chart'],
            ['cn_rei', 'china/housing-index', 'tradingeconomics-chart'],
        ];
        foreach ($definitions as [$metricId, $path, $source]) {
            $rows = $this->safe('Trading Economics ' . $metricId, fn (): array => $this->fetchTradingEconomics($path));
            if (!is_array($rows) || $rows === []) {
                continue;
            }
            $this->touch($source);
            $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision('TradingEconomics'));
        }
    }

    private function collectMonthlyMid(): void
    {
        $blsIds = [
            'LNS14000000', 'LNS11300000', 'CES0000000001', 'CES0500000003',
            'CUSR0000SA0', 'CUSR0000SA0L1E', 'WPSFD4',
        ];
        $bls = $this->safe('BLS labor and inflation', fn (): array => $this->fetchBls($blsIds));
        if (is_array($bls)) {
            $this->touch('bls-api');
            foreach ([['us_unemp', 'LNS14000000'], ['us_lfpr', 'LNS11300000']] as [$metricId, $series]) {
                $this->addPeriodRows($metricId, $bls[$series] ?? [], fn ($value) => $value, 'valid', $this->revision('BLS'));
            }
            $this->addMonthlyChange('us_nfp', $bls['CES0000000001'] ?? [], $this->revision('BLS-derived'));
            $this->addYoY('us_wages', $bls['CES0500000003'] ?? [], 'valid', $this->revision('BLS-derived'));
            $this->addYoY('us_cpi', $bls['CUSR0000SA0'] ?? [], 'valid', $this->revision('BLS-derived'));
            $this->addYoY('us_core_cpi', $bls['CUSR0000SA0L1E'] ?? [], 'valid', $this->revision('BLS-derived'));
            $this->addYoY('us_ppi', $bls['WPSFD4'] ?? [], 'valid', $this->revision('BLS-derived'));
        }

        $pce = $this->safe('BEA PCE', fn (): array => $this->fetchDbnomics('BEA', 'NIPA-T20804', 'DPCERG-M'));
        if (is_array($pce)) {
            $this->touch('bea-dbnomics');
            $this->addYoY('us_pce', $pce, 'valid', $this->revision('BEA-PCE-derived'));
        }

        $sinceMonth = $this->from->modify('-13 months')->format('Y-m');
        $untilMonth = $this->to->format('Y-m');
        $euroDefinitions = [
            ['ez_retail', 'sts_trtu_m', ['freq' => 'M', 'indic_bt' => 'VOL_SLS', 'nace_r2' => 'G', 's_adj' => 'CA', 'unit' => 'I21', 'geo' => 'EA20'], 'yoy'],
            ['ez_indpro', 'sts_inpr_m', ['freq' => 'M', 'indic_bt' => 'PRD', 'nace_r2' => 'B-D', 's_adj' => 'CA', 'unit' => 'I21', 'geo' => 'EA20'], 'yoy'],
            ['ez_cci', 'ei_bssi_m_r2', ['freq' => 'M', 'indic' => 'BS-CSMCI-BAL', 's_adj' => 'SA', 'geo' => 'EA21'], 'direct'],
            ['ez_cpi', 'prc_hicp_minr', ['freq' => 'M', 'unit' => 'RCH_A', 'coicop18' => 'TOTAL', 'geo' => 'EA21'], 'direct'],
            ['ez_unemp', 'ei_lmhr_m', ['freq' => 'M', 'unit' => 'PC_ACT', 's_adj' => 'SA', 'indic' => 'LM-UN-T-TOT', 'geo' => 'EA21'], 'direct'],
        ];
        foreach ($euroDefinitions as [$metricId, $dataset, $parameters, $mode]) {
            $parameters['sinceTimePeriod'] = $sinceMonth;
            $parameters['untilTimePeriod'] = $untilMonth;
            $rows = $this->safe('Eurostat ' . $metricId, fn (): array => $this->fetchEurostat($dataset, $parameters));
            if (!is_array($rows)) {
                continue;
            }
            $this->touch('eurostat-api');
            if ($mode === 'yoy') {
                $this->addYoY($metricId, $rows, 'valid', $this->revision('Eurostat-derived'));
            } else {
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision('Eurostat'));
            }
        }

        foreach ([
            ['us_indpro', 'G17_IP_SPECIAL_AGGREGATES', 'IP.B50001.S', 'yoy'],
            ['us_durable', 'G17_IP_SPECIAL_AGGREGATES', 'IP.X005MV.S', 'mom'],
            ['us_capu', 'G17_CAPUTL', 'CAPUTL.B50001.S', 'direct'],
        ] as [$metricId, $dataset, $series, $mode]) {
            $rows = $this->safe('Federal Reserve ' . $metricId, fn (): array => $this->fetchDbnomics('FED', $dataset, $series));
            if (!is_array($rows)) {
                continue;
            }
            $this->touch('fed-dbnomics');
            if ($mode === 'yoy') {
                $this->addYoY($metricId, $rows, 'valid', $this->revision('FED-derived'));
            } elseif ($mode === 'mom') {
                $this->addMonthlyPctChange($metricId, $rows, 'valid', $this->revision('FED-derived'));
            } else {
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision('FED'));
            }
        }

        $retail = $this->safe('Census retail sales', fn (): array => $this->fetchCensusProgram('MRTS', ['1']));
        if (is_array($retail)) {
            $this->touch('census-download');
            $source = array_values(array_filter($retail, static fn (array $row): bool => $row['value'] !== null));
            $this->addYoY('us_retail', $source, 'revised', $this->revision('Census-MRTS-derived'));
        }
        $housing = $this->safe('Census residential construction', fn (): array => $this->fetchCensusProgram('RESCONST', ['1', '4']));
        if (is_array($housing)) {
            $this->touch('census-download');
            foreach ([['us_permits', '1'], ['us_starts', '4']] as [$metricId, $category]) {
                $source = array_values(array_filter($housing, static fn (array $row): bool =>
                    $row['category'] === $category && $row['value'] !== null));
                $this->addPeriodRows($metricId, $source, fn ($value) => $value, 'revised', $this->revision('Census-RESCONST'));
            }
        }

        $oecdCpi = $this->safe('OECD G20 CPI', fn (): array => $this->fetchOecdCsv(
            'OECD.SDD.TPS,DSD_G20_PRICES@DF_G20_PRICES,1.0',
            'G20+CHN+JPN.M.N.CPI.PA._T.N.GY',
            $sinceMonth,
            $untilMonth,
        ));
        if (is_array($oecdCpi)) {
            $this->touch('oecd-sdmx');
            $metrics = ['G20' => 'world_cpi_yoy', 'CHN' => 'cn_cpi', 'JPN' => 'jp_cpi'];
            foreach ($oecdCpi as $row) {
                if (isset($metrics[$row['area']])) {
                    $this->add(
                        $metrics[$row['area']],
                        $this->periodToDate($row['period']),
                        $row['value'],
                        $row['status'] === 'P' ? 'provisional' : 'valid',
                        $this->revision('OECD-G20-CPI'),
                    );
                }
            }
        }

        $commodityDefinitions = [
            'world_commodity_index' => 'PALLFNF',
            'world_energy_index' => 'PNRG',
            'world_food_index' => 'PFOOD',
            'world_metals_index' => 'PMETA',
        ];
        $commodity = $this->safe('IMF/FRED commodity indices', fn (): array => $this->fetchCommodityIndices());
        $commodityRows = is_array($commodity['rows'] ?? null) ? $commodity['rows'] : null;
        if (is_array($commodityRows)) {
            $this->touch((string) $commodity['source']);
            foreach ($commodityDefinitions as $metricId => $series) {
                $rows = $commodityRows[$series] ?? [];
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'valid', $this->revision((string) $commodity['revision'] . '-' . $series));
            }
        }
        if (isset($commodityRows['PNRG'])) {
            $this->touch('derived-metrics');
            $this->addYoY('world_energy_yoy', $commodityRows['PNRG'], 'valid', $this->revision('IMF-energy-derived'));
        }
        if (isset($commodityRows['PFOOD'])) {
            $this->touch('derived-metrics');
            $this->addYoY('world_food_yoy', $commodityRows['PFOOD'], 'valid', $this->revision('IMF-food-derived'));
        }
    }

    private function collectMonthlyLate(): void
    {
        $this->collectPbocRate();

        foreach ([
            ['us_nom_gdp', 'NIPA-T10105', 'A191RC-Q', 'nominal'],
            ['us_gdp_yoy', 'NIPA-T10111', 'A191RO-Q', 'direct'],
        ] as [$metricId, $dataset, $series, $mode]) {
            $rows = $this->safe('BEA ' . $metricId, fn (): array => $this->fetchDbnomics('BEA', $dataset, $series));
            if (is_array($rows)) {
                $this->touch('bea-dbnomics');
                $transform = $mode === 'nominal'
                    ? fn ($value) => ($number = $this->number($value)) === null ? null : $number / 1000.0
                    : fn ($value) => $value;
                $this->addPeriodRows($metricId, $rows, $transform, 'revised', $this->revision('BEA'));
            }
        }

        $china = $this->safe('NBS China GDP', fn (): array => $this->fetchChinaData('china-gdp-quarterly'));
        if (is_array($china)) {
            $this->touch('nbs-china-data');
            $this->addPeriodRows(
                'cn_nom_gdp',
                $china,
                fn ($value) => ($number = $this->number($value)) === null ? null : $number / 10.0 * 4.0,
                'revised',
                $this->revision('NBS-ChinaData-annual-rate'),
            );
        }

        $quarterStart = $this->quarterCode($this->from->modify('-15 months'));
        $quarterEnd = $this->quarterCode($this->to);
        $nominalFlow = 'OECD.SDD.NAD,DSD_NAMAIN1@DF_QNA_EXPENDITURE_NATIO_CURR,1.1';
        foreach ([['jp_nom_gdp', 'JPN'], ['gr_nom_gdp', 'DEU']] as [$metricId, $country]) {
            $rows = $this->safe('OECD nominal GDP ' . $country, fn (): array => $this->fetchOecdCsv(
                $nominalFlow,
                'Q.Y.' . $country . '.S1..B1GQ.....V..',
                $quarterStart,
                $quarterEnd,
            ));
            if (is_array($rows)) {
                $this->touch('oecd-sdmx');
                $this->addPeriodRows(
                    $metricId,
                    $rows,
                    fn ($value) => ($number = $this->number($value)) === null ? null : $number / 1000.0 * 4.0,
                    'valid',
                    $this->revision('OECD-QNA-annual-rate'),
                );
            }
        }

        foreach ([
            ['cn_gdp_yoy', 'CHN'], ['jp_gdp_yoy', 'JPN'], ['gr_gdp_yoy', 'DEU'],
        ] as [$metricId, $country]) {
            $series = 'Q.Y.' . $country . '.S1.S1.B1GQ._Z._Z._Z.PC.L.GY.T0102';
            $rows = $this->safe('OECD GDP growth ' . $country, fn (): array => $this->fetchDbnomics(
                'OECD',
                'DSD_NAMAIN1@DF_QNA_EXPENDITURE_GROWTH_G20',
                $series,
            ));
            if (is_array($rows)) {
                $this->touch('oecd-dbnomics');
                $this->addPeriodRows($metricId, $rows, fn ($value) => $value, 'revised', $this->revision('OECD-GDP-YOY'));
            }
        }

        $g20 = $this->safe('OECD G20 GDP growth', fn (): array => $this->fetchOecdCsv(
            'OECD.SDD.NAD,DSD_NAMAIN1@DF_QNA_EXPENDITURE_GROWTH_G20,1.1',
            'Q.Y.G20.S1.S1.B1GQ._Z._Z._Z.PC.L.GY.T0102',
            $quarterStart,
            $quarterEnd,
        ));
        if (is_array($g20)) {
            $this->touch('oecd-sdmx');
            $this->addPeriodRows('world_gdp_yoy', $g20, fn ($value) => $value, 'valid', $this->revision('OECD-G20-GDP-YOY'));
        }

        $euroNominal = $this->safe('Eurostat nominal GDP', fn (): array => $this->fetchEurostat('namq_10_gdp', [
            'freq' => 'Q', 'unit' => 'CP_MEUR', 'na_item' => 'B1GQ', 's_adj' => 'SCA', 'geo' => 'EA21',
            'sinceTimePeriod' => $quarterStart, 'untilTimePeriod' => $quarterEnd,
        ]));
        if (is_array($euroNominal)) {
            $this->touch('eurostat-api');
            $this->addPeriodRows(
                'ez_nom_gdp',
                $euroNominal,
                fn ($value) => ($number = $this->number($value)) === null ? null : $number / 1000.0 * 4.0,
                'valid',
                $this->revision('Eurostat-EA21-annual-rate'),
            );
        }
        $euroReal = $this->safe('Eurostat real GDP', fn (): array => $this->fetchEurostat('namq_10_gdp', [
            'freq' => 'Q', 'unit' => 'CLV05_MEUR', 'na_item' => 'B1GQ', 's_adj' => 'NSA', 'geo' => 'EA',
            'sinceTimePeriod' => $quarterStart, 'untilTimePeriod' => $quarterEnd,
        ]));
        if (is_array($euroReal)) {
            $this->touch('derived-metrics');
            $this->addYoY('ez_gdp_yoy', $euroReal, 'valid', $this->revision('Eurostat-GDP-derived'));
        }

        $cli = $this->safe('OECD G20 CLI', fn (): array => $this->fetchOecdCsv(
            'OECD.SDD.STES,DSD_STES@DF_CLI,4.1',
            'G20.M.LI...AA...H',
            $this->from->format('Y-m'),
            $this->to->format('Y-m'),
        ));
        if (is_array($cli)) {
            $this->touch('oecd-cli');
            $this->addPeriodRows('world_cli', $cli, fn ($value) => $value, 'valid', $this->revision('OECD-CLI-G20'));
        }

        $cpb = $this->safe('CPB World Trade Monitor', fn (): array => $this->fetchCpbWorkbook());
        if (is_array($cpb)) {
            $this->touch('cpb-world-trade-monitor');
            $this->addPeriodRows('world_trade_volume', $cpb['trade'] ?? [], fn ($value) => $value, 'valid', $this->revision('CPB-WTM'));
            $this->addPeriodRows('world_indpro', $cpb['industry'] ?? [], fn ($value) => $value, 'valid', $this->revision('CPB-WTM'));
        }

        $ecbBalance = $this->safe('ECB balance sheet', fn (): array => $this->fetchDbnomics(
            'ECB', 'BSI', 'M.4F.N.N.T00.A.1.Z5.0000.Z01.E',
        ));
        if (is_array($ecbBalance)) {
            $this->touch('ecb-dbnomics');
            $this->addPeriodRows('ecb_bs', $ecbBalance, fn ($value) => $value, 'revised', $this->revision('ECB-BSI'));
        }
        $bojBalance = $this->safe('BOJ balance sheet', fn (): array => $this->fetchBoj('BS01', 'MABJMTA', $this->from->format('Y-m')));
        if (is_array($bojBalance)) {
            $this->touch('boj-api');
            $this->addPeriodRows(
                'boj_bs',
                $bojBalance,
                fn ($value) => ($number = $this->number($value)) === null || $number <= 0.0
                    ? null
                    : $number / 10_000.0,
                'revised',
                $this->revision('BOJ-BS01'),
            );
        }
        $bis = $this->safe('BIS PBoC balance sheet', fn (): array => $this->fetchBisPboc());
        if (is_array($bis)) {
            $this->touch('bis-cbta');
            $this->addPeriodRows('pboc_bs', $bis, fn ($value) => $value, 'revised', $this->revision('BIS-WS-CBTA'));
        }

        foreach ([
            ['sp_pe', 's-p-500-pe-ratio/table/by-month'],
            ['sp_cape', 'shiller-pe/table/by-month'],
            ['sp_div', 's-p-500-dividend-yield/table/by-month'],
        ] as [$metricId, $path]) {
            $html = $this->safe('Multpl ' . $metricId, fn (): string => $this->http->getText(
                'https://www.multpl.com/' . $path,
                ['Accept' => 'text/html,*/*'],
            ));
            if (is_string($html)) {
                $parsed = $this->parseMultpl($html);
                if ($parsed === []) {
                    $this->errors[] = ['source' => 'Multpl ' . $metricId, 'code' => 'EMPTY_SOURCE'];
                    continue;
                }
                $this->touch('multpl-sp500-valuation');
                $this->addPeriodRows($metricId, $parsed, fn ($value) => $value, 'valid', $this->revision('Multpl-published'));
            }
        }
        $lense = $this->safe('LENSE S&P 500 P/B', fn (): string => $this->http->getText(
            'https://lense-analytics.com/fundamentals/sp500-pb-ratio',
            ['Accept' => 'text/html,*/*'],
        ));
        if (is_string($lense)) {
            $rows = array_values(array_filter($this->parseLense($lense), static fn (array $row): bool => $row['date'] >= '2025-10-01'));
            if ($rows === []) {
                $this->errors[] = ['source' => 'LENSE S&P 500 P/B', 'code' => 'EMPTY_SOURCE'];
            } else {
                $this->touch('lense-sp500-pb');
                $this->addPeriodRows('sp_pb', $rows, fn ($value) => $value, 'valid', $this->revision('LENSE-company-filings'));
            }
        }
    }

    private function fetchBisPboc(): array
    {
        $url = 'https://stats.bis.org/api/v2/data/dataflow/BIS/WS_CBTA/1.0/M.CN.B.XDC.CNY.N?format=csv'
            . '&startPeriod=' . rawurlencode($this->from->format('Y-m'));
        $rows = $this->parseCsv($this->http->getText($url, ['Accept' => 'text/csv,*/*']));
        if ($rows === []) {
            throw new RuntimeException('BIS CSV empty.');
        }
        $headers = array_map('trim', array_shift($rows));
        $dateIndex = array_search('TIME_PERIOD', $headers, true);
        $valueIndex = array_search('OBS_VALUE', $headers, true);
        $areaIndex = array_search('REF_AREA', $headers, true);
        if ($dateIndex === false || $valueIndex === false || $areaIndex === false) {
            throw new RuntimeException('BIS CSV columns missing.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (($row[$areaIndex] ?? '') === 'CN') {
                $result[] = ['period' => $row[$dateIndex] ?? '', 'value' => $row[$valueIndex] ?? null];
            }
        }
        return $result;
    }

    private function quarterCode(DateTimeImmutable $date): string
    {
        return $date->format('Y') . '-Q' . (int) ceil(((int) $date->format('n')) / 3);
    }
}
