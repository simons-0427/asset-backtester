<?php

declare(strict_types=1);

use Smstark\Backtester\App;

require_once __DIR__ . '/autoload.php';

$configuredPath = getenv('BACKTESTER_CONFIG');
$configPath = is_string($configuredPath) && $configuredPath !== ''
    ? $configuredPath
    : dirname(__DIR__, 2) . '/config/backtester.php';

if (!is_file($configPath)) {
    throw new RuntimeException('Backtester-Konfiguration fehlt. config/backtester.example.php kopieren und ausfüllen.');
}

$config = require $configPath;
if (!is_array($config)) {
    throw new RuntimeException('Backtester-Konfiguration muss ein PHP-Array zurückgeben.');
}

date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Europe/Berlin'));
ini_set('display_errors', ($config['app']['environment'] ?? 'production') === 'development' ? '1' : '0');

return new App($config);

