<?php

declare(strict_types=1);

namespace AssetBacktester\Providers;

use AssetBacktester\Exceptions\ProviderException;

final class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 20,
        private readonly int $maxRetries = 3,
        private readonly int $retryBaseMs = 500,
        private readonly bool $preferPhpStream = false,
        private readonly ?string $caBundlePath = null,
    ) {
    }

    public function get(string $url, array $headers = []): string
    {
        $requestHeaders = array_merge([
            'Accept: application/json, text/csv;q=0.9',
            'User-Agent: smstark-asset-backtester/1.0',
            'Connection: close',
        ], $headers);

        // STRATO can reach FRED from the shell while its PHP-cURL transport
        // stalls. Providers may explicitly use PHP's verified HTTPS stream.
        if ($this->preferPhpStream) {
            return $this->getWithPhpStream($url, $requestHeaders);
        }

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

            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                // STRATO's cURL/HTTP2 combination can abort valid provider
                // responses with CURLE_HTTP2_STREAM (92). All configured data
                // providers support HTTP/1.1, so prefer it for stable cron runs.
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                // The STRATO PHP-cURL build may otherwise prefer an unstable
                // IPv6 route or keep the small FRED response open until the
                // request timeout. Cron requests are short and independent.
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($this->caBundlePath !== null && $this->caBundlePath !== '') {
                $resolvedCaBundle = realpath($this->caBundlePath);
                if ($resolvedCaBundle === false || !is_file($resolvedCaBundle) || !is_readable($resolvedCaBundle)) {
                    curl_close($curl);
                    throw new ProviderException(
                        'Das konfigurierte CA-Zertifikatspaket ist nicht lesbar.',
                        'CA_BUNDLE_MISSING',
                    );
                }
                $curlOptions[CURLOPT_CAINFO] = $resolvedCaBundle;
            }
            curl_setopt_array($curl, $curlOptions);

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
     * @param list<string> $headers
     */
    private function getWithPhpStream(string $url, array $headers): string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new ProviderException(
                'Der PHP-HTTPS-Stream ist auf dem Server deaktiviert.',
                'PHP_STREAM_MISSING',
            );
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new ProviderException('Nur HTTPS-Datenquellen sind erlaubt.', 'HTTP_SCHEME_REJECTED');
        }
        foreach ($headers as $header) {
            if (!is_string($header) || str_contains($header, "\r") || str_contains($header, "\n")) {
                throw new ProviderException('Ungültiger HTTP-Header.', 'HTTP_HEADER_REJECTED');
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'protocol_version' => 1.1,
                'timeout' => max(1, $this->timeoutSeconds),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = isset($http_response_header) && is_array($http_response_header)
            ? $http_response_header
            : [];
        $status = self::statusFromResponseHeaders($responseHeaders);
        if (is_string($body) && $status >= 200 && $status < 300) {
            return $body;
        }
        if ($status > 0) {
            [$code, $message] = self::describeFailure($status, 0);
            throw new ProviderException($message, $code);
        }

        throw new ProviderException(
            'Die Verbindung zum Datenanbieter ist fehlgeschlagen (PHP-Stream).',
            'HTTP_TRANSPORT_ERROR',
        );
    }

    /**
     * @param list<string> $headers
     */
    public static function statusFromResponseHeaders(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|$)/i', trim($header), $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        return $status;
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
