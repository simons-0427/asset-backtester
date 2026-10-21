<?php

declare(strict_types=1);

use Smstark\Backtester\Providers\CsvImportProvider;
use Smstark\Backtester\Tests\Assertions as A;
use Smstark\Backtester\Tools\BtcUsdDailyPreparer;

use function Smstark\Backtester\Tests\assetFixture;

return [
    'BTC/USD-Minutenkurse werden mit historischen EZB-Kursen zu UTC-Tageswerten' => static function (): void {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backtester-btc-' . bin2hex(random_bytes(4));
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Testverzeichnis konnte nicht erstellt werden.');
        }
        $minuteFile = $directory . DIRECTORY_SEPARATOR . 'minute.csv';
        $fxFile = $directory . DIRECTORY_SEPARATOR . 'fx.csv';
        $outputFile = $directory . DIRECTORY_SEPARATOR . 'bitcoin.csv';
        try {
            file_put_contents($minuteFile, implode("\n", [
                'Timestamp,Open,High,Low,Close,Volume',
                '1704067200,100,100,100,100,1',
                '1704153540,110,110,110,110,1',
                '1704196800,120,120,120,120,1',
                '1704326340,130,130,130,130,1',
            ]) . "\n");
            file_put_contents($fxFile, implode("\n", [
                'CURRENCY,CURRENCY_DENOM,TIME_PERIOD,OBS_VALUE',
                'USD,EUR,2023-12-29,1.1',
                'USD,EUR,2024-01-02,1.2',
                'USD,EUR,2024-01-03,1.3',
            ]) . "\n");

            $report = (new BtcUsdDailyPreparer())->prepare(
                $minuteFile,
                $fxFile,
                $outputFile,
                new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC')),
                new DateTimeImmutable('2024-01-03', new DateTimeZone('UTC')),
            );
            A::same(3, $report['days_written']);
            A::same(1, $report['days_with_carried_fx_rate']);

            $rows = CsvImportProvider::parseCsv(
                (string) file_get_contents($outputFile),
                assetFixture(slug: 'bitcoin', providerType: 'csv'),
                new DateTimeImmutable('2024-01-01'),
                new DateTimeImmutable('2024-01-03'),
            );
            A::same(3, count($rows));
            A::same('100.0000000000', $rows[0]->value);
            A::same('EUR', $rows[0]->currency);
            A::same('2024-01-01 23:59:00', $rows[0]->sourceTimestamp?->format('Y-m-d H:i:s'));
            A::true(str_contains((string) $rows[0]->sourceRevision, 'ecb-usd-eur:2023-12-29'));
        } finally {
            foreach ([$outputFile, $fxFile, $minuteFile] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    },
];
