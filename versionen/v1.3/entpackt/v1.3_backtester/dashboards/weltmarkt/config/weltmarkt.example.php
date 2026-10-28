<?php

declare(strict_types=1);

// Diese Datei ist nur eine Vorlage. Die produktive Kopie gehört außerhalb des Webroots.
return [
    'app' => [
        'environment' => 'production',
        'timezone' => 'Europe/Berlin',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'weltmarkt',
        'username' => 'weltmarkt',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'limits' => [
        'max_range_days' => 7000,
    ],
    'imports' => [
        'enabled' => true,
        'external_trigger_token' => 'CHANGE_ME_WITH_AT_LEAST_32_RANDOM_CHARACTERS',
        'http_timeout_seconds' => 45,
        'max_response_bytes' => 25_000_000,
        'max_rows_per_job' => 50_000,
        'max_jobs_per_run' => 5,
        'max_runtime_seconds' => 700,
        'external_max_runtime_seconds' => 75,
        'lock_seconds' => 900,
        'user_agent' => 'smstark-weltmarkt-import/1.0 (+https://smstark-projekt.de/dashboards/weltmarkt/)',
        'allowed_hosts' => [
            'api.db.nomics.world',
            'api.statistiken.bundesbank.de',
            'chinadata.live',
            'data.bls.gov',
            'data-api.ecb.europa.eu',
            'ec.europa.eu',
            'fred.stlouisfed.org',
            'home.treasury.gov',
            'lense-analytics.com',
            'query1.finance.yahoo.com',
            'query2.finance.yahoo.com',
            'sdmx.oecd.org',
            'stats.bis.org',
            'tradingeconomics.com',
            'www.bankofengland.co.uk',
            'www.census.gov',
            'www.chinamoney.com.cn',
            'www.cpb.nl',
            'www.imf.org',
            'www.mof.go.jp',
            'www.multpl.com',
            'www.stat-search.boj.or.jp',
            'www.stoxx.com',
            'yield.chinabond.com.cn',
        ],
    ],
];
