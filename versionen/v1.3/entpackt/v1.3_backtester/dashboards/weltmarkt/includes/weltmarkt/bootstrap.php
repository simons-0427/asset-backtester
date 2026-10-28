<?php

declare(strict_types=1);

use Smstark\Weltmarkt\App;

require_once __DIR__ . '/autoload.php';

$configuredPath = getenv('WELTMARKT_CONFIG');
$configPath = is_string($configuredPath) && $configuredPath !== ''
    ? $configuredPath
    : dirname(__DIR__, 2) . '/config/weltmarkt.php';

if (!is_file($configPath)) {
    throw new RuntimeException('Weltmarkt-Konfiguration fehlt.');
}

$config = require $configPath;
if (!is_array($config)) {
    throw new RuntimeException('Die Weltmarkt-Konfiguration muss ein PHP-Array zurückgeben.');
}

date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Europe/Berlin'));
ini_set('display_errors', ($config['app']['environment'] ?? 'production') === 'development' ? '1' : '0');

return new App($config);
