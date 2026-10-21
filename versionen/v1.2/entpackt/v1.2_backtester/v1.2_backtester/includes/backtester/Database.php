<?php

declare(strict_types=1);

namespace Smstark\Backtester;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(array $config): PDO
    {
        foreach (['host', 'port', 'name', 'username', 'password'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException('Unvollständige Datenbankkonfiguration.');
            }
        }

        if (in_array($config['name'], ['', 'CHANGE_ME'], true)
            || in_array($config['username'], ['', 'CHANGE_ME'], true)) {
            throw new RuntimeException('Die Backtester-Datenbank ist noch nicht konfiguriert.');
        }

        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            (int) $config['port'],
            $config['name'],
            $charset
        );

        $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}

