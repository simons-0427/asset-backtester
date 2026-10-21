<?php

declare(strict_types=1);

namespace Smstark\Backtester\Http;

final class RequestSecurity
{
    public static function isCommandLineExecution(): bool
    {
        return PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']);
    }

    public static function commandLineOptions(array $allowedNames): array
    {
        if (!self::isCommandLineExecution()) {
            return [];
        }

        $allowed = array_fill_keys($allowedNames, true);
        $arguments = array_values(array_slice($_SERVER['argv'] ?? [], 1));
        $options = [];
        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = (string) $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                continue;
            }

            $option = substr($argument, 2);
            $parts = explode('=', $option, 2);
            $name = $parts[0];
            if (!isset($allowed[$name])) {
                continue;
            }

            if (isset($parts[1])) {
                $options[$name] = $parts[1];
                continue;
            }

            $next = $arguments[$index + 1] ?? null;
            if (is_string($next) && !str_starts_with($next, '--')) {
                $options[$name] = $next;
                $index++;
            } else {
                $options[$name] = '';
            }
        }

        return $options;
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            JsonResponse::error('METHOD_NOT_ALLOWED', 'Für diesen Endpunkt ist POST erforderlich.', 405);
        }
    }

    public static function requireSameOrigin(array $config): void
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $allowed = rtrim((string) ($config['allowed_origin'] ?? ''), '/');
        if ($origin !== '' && $allowed !== '' && rtrim($origin, '/') !== $allowed) {
            JsonResponse::error('ORIGIN_DENIED', 'Anfrage von dieser Herkunft nicht erlaubt.', 403);
        }

        if (($_SERVER['HTTP_X_BACKTESTER_REQUEST'] ?? '') !== 'page-visit') {
            JsonResponse::error('REQUEST_HEADER_REQUIRED', 'Ungültige Synchronisationsanfrage.', 403);
        }
    }

    public static function requireCliOrCronSecret(array $config): void
    {
        if (self::isCommandLineExecution()) {
            return;
        }

        $configured = (string) ($config['cron_secret'] ?? '');
        $provided = self::cronSecretFromRequest($_SERVER, $_GET);
        if ($configured === '' || !hash_equals($configured, $provided)) {
            JsonResponse::error('CRON_AUTH_FAILED', 'Cron-Autorisierung fehlgeschlagen.', 403);
        }
    }

    public static function cronSecretFromRequest(array $server, array $query): string
    {
        $headerSecret = $server['HTTP_X_BACKTESTER_CRON_TOKEN'] ?? '';
        if (is_string($headerSecret) && $headerSecret !== '') {
            return $headerSecret;
        }

        $querySecret = $query['token'] ?? '';
        return is_string($querySecret) ? $querySecret : '';
    }
}
