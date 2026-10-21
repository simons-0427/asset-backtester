<?php

declare(strict_types=1);

use Smstark\Backtester\Services\SyncPolicy;
use Smstark\Backtester\Tests\Assertions as A;

return [
    'Retry-Backoff wächst exponentiell und ist begrenzt' => static function (): void {
        $now = new DateTimeImmutable('2026-08-03 08:00:00 UTC');
        A::same('2026-08-03 09:00', SyncPolicy::nextFailureCheck($now, 0, 48)->format('Y-m-d H:i'));
        A::same('2026-08-03 12:00', SyncPolicy::nextFailureCheck($now, 2, 48)->format('Y-m-d H:i'));
        A::same('2026-08-05 08:00', SyncPolicy::nextFailureCheck($now, 12, 48)->format('Y-m-d H:i'));
    },
    'Abgelaufene Locks können übernommen werden' => static function (): void {
        $now = new DateTimeImmutable('2026-08-03 08:00:00 UTC');
        A::true(SyncPolicy::lockExpired(null, $now));
        A::true(SyncPolicy::lockExpired('2026-08-03 07:59:59 UTC', $now));
        A::true(!SyncPolicy::lockExpired('2026-08-03 08:00:01 UTC', $now));
    },
];

