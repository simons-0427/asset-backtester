<?php

declare(strict_types=1);

use Smstark\Backtester\Tools\BtcUsdDailyPreparer;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

function preparationOptions(array $arguments): array
{
    $options = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        if (in_array($name, ['input', 'fx', 'output', 'from', 'to'], true)) {
            $options[$name] = $value;
        }
    }
    return $options;
}

function preparationDate(string $value, string $name): DateTimeImmutable
{
    $timezone = new DateTimeZone('UTC');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new RuntimeException('Ungültiges ' . $name . ': erwartet wird JJJJ-MM-TT.');
    }
    return $date;
}

try {
    $root = dirname(__DIR__, 2);
    $options = preparationOptions($argv ?? []);
    $toDefault = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day');
    $to = preparationDate((string) ($options['to'] ?? $toDefault->format('Y-m-d')), 'Enddatum');
    $from = preparationDate((string) ($options['from'] ?? $to->modify('-10 years')->format('Y-m-d')), 'Startdatum');
    $input = (string) ($options['input'] ?? ($root . '/btcusd_1-min_data.csv'));
    $fx = (string) ($options['fx'] ?? ($root . '/var/import/ecb-usd-eur.csv'));
    $output = (string) ($options['output'] ?? ($root . '/var/import/bitcoin.csv'));

    $report = (new BtcUsdDailyPreparer())->prepare($input, $fx, $output, $from, $to);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    echo 'Vorbereitung fehlgeschlagen: ' . $error->getMessage() . PHP_EOL;
    exit(1);
}
