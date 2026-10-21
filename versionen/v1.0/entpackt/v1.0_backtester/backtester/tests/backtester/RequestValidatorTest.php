<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\ValidationException;
use Smstark\Backtester\Http\RequestValidator;
use Smstark\Backtester\Tests\Assertions as A;

$config = [
    'max_assets_per_request' => 3,
    'max_range_days' => 14610,
    'min_amount' => 100,
    'max_amount' => 10000000,
];

return [
    'API-Parameter werden normalisiert' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        $result = $validator->series([
            'assets' => 'sp500,bitcoin,sp500',
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'amount' => '10000',
        ], ['sp500', 'bitcoin', 'gold']);
        A::same(['sp500', 'bitcoin'], $result['assets']);
        A::near(10000, $result['amount']);
    },
    'Unbekanntes Asset wird abgelehnt' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        A::throws(static fn () => $validator->series([
            'assets' => 'free-url', 'start' => '2024-01-01', 'end' => '2024-12-31', 'amount' => 10000,
        ], ['sp500']), ValidationException::class);
    },
    'Zukunft, Datumsreihenfolge und Betrag werden abgelehnt' => static function () use ($config): void {
        $validator = new RequestValidator($config);
        foreach ([
            ['start' => '2024-01-01', 'end' => '2099-01-01', 'amount' => 10000],
            ['start' => '2024-12-31', 'end' => '2024-01-01', 'amount' => 10000],
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'amount' => 10],
        ] as $input) {
            A::throws(static fn () => $validator->series(['assets' => 'sp500'] + $input, ['sp500']), ValidationException::class);
        }
    },
];

