<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use Smstark\Backtester\Exceptions\ProviderException;

final class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 20,
        private readonly int $maxRetries = 3,
        private readonly int $retryBaseMs = 500,
    ) {
    }

    public function get(string $url, array $headers = []): string
    {
        if (!function_exists('curl_init')) {
            throw new ProviderException('Die PHP-cURL-Erweiterung ist nicht verfügbar.', 'CURL_MISSING');
        }

        $lastStatus = 0;
        $lastErrorNumber = 0;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new ProviderException('HTTP-Client konnte nicht initialisiert werden.', 'HTTP_INIT_FAILED');
            }

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => array_merge([
                    'Accept: application/json, text/csv;q=0.9',
                    'User-Agent: smstark-asset-backtester/1.0',
                ], $headers),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($curl);
            $errorNumber = curl_errno($curl);
            $lastErrorNumber = $errorNumber;
            $lastStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if ($errorNumber === 0 && is_string($body) && $lastStatus >= 200 && $lastStatus < 300) {
                return $body;
            }

            $retryable = $errorNumber !== 0 || $lastStatus === 429 || $lastStatus >= 500;
            if (!$retryable || $attempt >= $this->maxRetries) {
                break;
            }

            $delayMs = $this->retryBaseMs * (2 ** $attempt) + random_int(0, 200);
            usleep($delayMs * 1000);
        }

        [$code, $message] = self::describeFailure($lastStatus, $lastErrorNumber);
        throw new ProviderException($message, $code);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function describeFailure(int $status, int $curlErrorNumber): array
    {
        if ($curlErrorNumber !== 0) {
            return [
                'HTTP_TRANSPORT_ERROR',
                sprintf('Die Verbindung zum Datenanbieter ist fehlgeschlagen (cURL %d).', $curlErrorNumber),
            ];
        }

        return match ($status) {
            401 => ['HTTP_UNAUTHORIZED', 'Der Datenanbieter hat den API-Key abgelehnt (HTTP 401).'],
            403 => ['HTTP_FORBIDDEN', 'Der Datenanbieter hat den Zugriff oder Tarif abgelehnt (HTTP 403).'],
            404 => ['HTTP_NOT_FOUND', 'Der angeforderte Daten-Endpunkt wurde nicht gefunden (HTTP 404).'],
            429 => ['RATE_LIMITED', 'Das Abruflimit des Datenanbieters wurde erreicht (HTTP 429).'],
            500, 502, 503, 504 => [
                'PROVIDER_SERVER_ERROR',
                sprintf('Der Datenanbieter meldet einen Serverfehler (HTTP %d).', $status),
            ],
            default => [
                'HTTP_REQUEST_FAILED',
                $status > 0
                    ? sprintf('Die Anfrage an den Datenanbieter ist fehlgeschlagen (HTTP %d).', $status)
                    : 'Der Datenanbieter ist vorübergehend nicht erreichbar.',
            ],
        };
    }
}
