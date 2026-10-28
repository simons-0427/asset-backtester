<?php

declare(strict_types=1);

use Smstark\Backtester\Services\SyncPolicy;
use Smstark\Backtester\Tests\Assertions as A;

return [
    'Kalendertägliche Reihen werden am Folgetag wieder fällig' => static function (): void {
        $now = new DateTimeImmutable('2026-09-11 11:00:00 UTC');
        $next = SyncPolicy::nextAssetSuccessCheck($now, 'daily', 'calendar_daily', []);
        A::same('2026-09-12 00:00', $next->format('Y-m-d H:i'));
    },
    'Marktreihen überspringen das Wochenende' => static function (): void {
        $now = new DateTimeImmutable('2026-09-11 11:00:00 UTC');
        $next = SyncPolicy::nextAssetSuccessCheck($now, 'daily', 'market_weekdays', []);
        A::same('2026-09-14 00:00', $next->format('Y-m-d H:i'));
    },
    'Monatsreihen werden nach sieben Tagen wieder geprüft' => static function (): void {
        $now = new DateTimeImmutable('2026-09-12 11:00:00 UTC');
        $next = SyncPolicy::nextAssetSuccessCheck($now, 'monthly', 'native_periods', [
            'monthly_check_interval_days' => 7,
        ]);
        A::same('2026-09-19 00:00', $next->format('Y-m-d H:i'));
    },
    'Quartalsreihen werden nach 30 Tagen wieder geprüft' => static function (): void {
        $now = new DateTimeImmutable('2026-09-12 11:00:00 UTC');
        $next = SyncPolicy::nextAssetSuccessCheck($now, 'quarterly', 'native_periods', [
            'quarterly_check_interval_days' => 30,
        ]);
        A::same('2026-10-12 00:00', $next->format('Y-m-d H:i'));
    },
    'Unbekannte Frequenzen verwenden die bisherige Stundenregel' => static function (): void {
        $now = new DateTimeImmutable('2026-09-12 11:00:00 UTC');
        $next = SyncPolicy::nextAssetSuccessCheck($now, 'annual', 'native_periods', [
            'success_check_delay_hours' => 18,
        ]);
        A::same('2026-09-13 05:00', $next->format('Y-m-d H:i'));
    },
    'Retry-Backoff wächst exponentiell und ist begrenzt' => static function (): void {
        $now = new DateTimeImmutable('2026-08-03 08:00:00 UTC');
        A::same('2026-08-03 09:00', SyncPolicy::nextFailureCheck($now, 0, 48)->format('Y-m-d H:i'));
        A::same('2026-08-03 12:00', SyncPolicy::nextFailureCheck($now, 2, 48)->format('Y-m-d H:i'));
        A::same('2026-08-05 08:00', SyncPolicy::nextFailureCheck($now, 12, 48)->format('Y-m-d H:i'));
    },
    'Tagesreihen warten nach Fehlern höchstens 24 Stunden' => static function (): void {
        $now = new DateTimeImmutable('2026-08-03 08:00:00 UTC');
        $next = SyncPolicy::nextAssetFailureCheck($now, 12, 'daily', []);
        A::same('2026-08-04 08:00', $next->format('Y-m-d H:i'));
    },
    'Langsame Reihen behalten den allgemeinen Fehler-Backoff' => static function (): void {
        $now = new DateTimeImmutable('2026-08-03 08:00:00 UTC');
        $next = SyncPolicy::nextAssetFailureCheck($now, 12, 'monthly', []);
        A::same('2026-08-05 08:00', $next->format('Y-m-d H:i'));
    },
    'Abgelaufene Locks können übernommen werden' => static function (): void {
        $now = new DateTimeImmutable('2026-08-03 08:00:00 UTC');
        A::true(SyncPolicy::lockExpired(null, $now));
        A::true(SyncPolicy::lockExpired('2026-08-03 07:59:59 UTC', $now));
        A::true(!SyncPolicy::lockExpired('2026-08-03 08:00:01 UTC', $now));
    },
];
