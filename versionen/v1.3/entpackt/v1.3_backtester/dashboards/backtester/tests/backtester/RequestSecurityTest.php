<?php

declare(strict_types=1);

use Smstark\Backtester\Http\RequestSecurity;
use Smstark\Backtester\Tests\Assertions as A;

return [
    'Cron-Geheimnis wird bevorzugt aus dem HTTP-Header gelesen' => static function (): void {
        A::same(
            'header-secret',
            RequestSecurity::cronSecretFromRequest(
                ['HTTP_X_BACKTESTER_CRON_TOKEN' => 'header-secret'],
                ['token' => 'query-secret']
            )
        );
    },
    'Cron-Geheimnis akzeptiert die bestehende URL als Rueckfall' => static function (): void {
        A::same(
            'query-secret',
            RequestSecurity::cronSecretFromRequest([], ['token' => 'query-secret'])
        );
        A::same('', RequestSecurity::cronSecretFromRequest([], ['token' => ['ungueltig']]));
    },
    'Kommandozeilenoptionen funktionieren ohne CLI-SAPI' => static function (): void {
        $previousArguments = $_SERVER['argv'] ?? null;
        try {
            $_SERVER['argv'] = [
                'backfill-history.php',
                '--asset=bitcoin',
                '--from',
                '2013-04-28',
                '--to=2026-08-02',
                '--ignored=value',
            ];
            A::same([
                'asset' => 'bitcoin',
                'from' => '2013-04-28',
                'to' => '2026-08-02',
            ], RequestSecurity::commandLineOptions(['asset', 'from', 'to']));
        } finally {
            if ($previousArguments === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArguments;
            }
        }
    },
];
