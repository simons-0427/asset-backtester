<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt\Import;

use RuntimeException;

final class HttpClient
{
    private const MAX_REDIRECTS = 4;

    /** @var list<string> */
    private array $allowedHosts;

    public function __construct(
        private readonly int $timeoutSeconds = 45,
        private readonly int $maxBytes = 25_000_000,
        private readonly string $userAgent = 'smstark-weltmarkt-import/1.0',
        array $allowedHosts = [],
    ) {
        $this->allowedHosts = array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => strtolower(rtrim(trim((string) $host), '.')),
            $allowedHosts,
        ))));
    }

    public function getText(string $url, array $headers = []): string
    {
        return $this->request('GET', $url, $headers);
    }

    public function getJson(string $url, array $headers = []): mixed
    {
        return $this->decodeJson($this->request('GET', $url, $headers), $url);
    }

    public function postJson(string $url, array $headers = [], ?string $body = null): mixed
    {
        return $this->decodeJson($this->request('POST', $url, $headers, $body ?? ''), $url);
    }

    private function request(string $method, string $url, array $headers, ?string $body = null): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-cURL-Erweiterung fehlt.');
        }
        $this->assertSafeHeaders($headers);

        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $currentUrl = $url;
            $currentMethod = $method;
            $currentBody = $body;
            $status = 0;
            $curlCode = 0;
            $curlError = '';

            for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
                $resolvedIp = $this->validateTarget($currentUrl);
                $response = $this->performRequest($currentMethod, $currentUrl, $headers, $currentBody, $resolvedIp, $attempt);
                $status = $response['status'];
                $curlCode = $response['curl_code'];
                $curlError = $response['curl_error'];

                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    if ($redirect === self::MAX_REDIRECTS || $response['location'] === null) {
                        throw new RuntimeException('Unsichere oder zu lange Weiterleitung der Datenquelle.');
                    }
                    $currentUrl = $this->resolveRedirect($currentUrl, $response['location']);
                    if (in_array($status, [301, 302, 303], true) && $currentMethod === 'POST') {
                        $currentMethod = 'GET';
                        $currentBody = null;
                    }
                    continue;
                }

                if ($curlCode === 0 && $status >= 200 && $status < 300) {
                    return $response['body'];
                }
                break;
            }

            $lastError = sprintf('HTTP %d%s', $status, $curlError !== '' ? ' (' . $curlError . ')' : '');
            $retryable = ($status === 0 || $status === 408 || $status === 429 || $status >= 500)
                && !in_array($curlCode, [28, 60], true);
            if (!$retryable || $attempt === 2) {
                break;
            }
            usleep(250_000 * $attempt);
        }

        throw new RuntimeException('Datenquelle nicht erreichbar: ' . ($lastError ?? 'unbekannter HTTP-Fehler'));
    }

    /** @return array{status:int,body:string,location:?string,curl_code:int,curl_error:string} */
    private function performRequest(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        string $resolvedIp,
        int $attempt,
    ): array {
        $buffer = '';
        $location = null;
        $tooLarge = false;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('HTTP-Client konnte nicht initialisiert werden.');
        }

        $headerLines = [];
        $hasAccept = false;
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Accept') === 0) {
                $hasAccept = true;
            }
            $headerLines[] = (string) $name . ': ' . (string) $value;
        }
        if (!$hasAccept) {
            $headerLines[] = 'Accept: application/json,text/csv,text/plain,text/html,*/*';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $pinnedAddress = str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp;
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(8, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTP_VERSION => $attempt === 1 ? 0 : 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [sprintf('%s:443:%s', $host, $pinnedAddress)],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, &$tooLarge): int {
                if (strlen($buffer) + strlen($chunk) > $this->maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $curlOptions[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($handle, $curlOptions);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body ?? '');
        }

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlCode = curl_errno($handle);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($tooLarge) {
            throw new RuntimeException('Antwort der Datenquelle überschreitet das Größenlimit.');
        }

        return [
            'status' => $status,
            'body' => $buffer,
            'location' => is_string($location) && $location !== '' ? $location : null,
            'curl_code' => $curlCode,
            'curl_error' => $curlError,
        ];
    }

    private function validateTarget(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException('Es sind nur gültige HTTPS-Datenquellen ohne Zugangsdaten erlaubt.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($this->allowedHosts === [] || !in_array($host, $this->allowedHosts, true)) {
            throw new RuntimeException('Die Datenquelle ist nicht freigegeben.');
        }

        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ip'])) $addresses[] = (string) $record['ip'];
                if (isset($record['ipv6'])) $addresses[] = (string) $record['ipv6'];
            }
        }
        if ($addresses === []) {
            $fallback = gethostbyname($host);
            if ($fallback !== $host) $addresses[] = $fallback;
        }

        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $address;
            }
        }
        throw new RuntimeException('Die Datenquelle löst nicht auf eine öffentliche IP-Adresse auf.');
    }

    private function resolveRedirect(string $currentUrl, string $location): string
    {
        $location = trim($location);
        if (filter_var($location, FILTER_VALIDATE_URL)) return $location;

        $parts = parse_url($currentUrl);
        $origin = 'https://' . (string) ($parts['host'] ?? '');
        if (str_starts_with($location, '//')) return 'https:' . $location;
        if (str_starts_with($location, '/')) return $origin . $location;
        if (str_starts_with($location, '?')) {
            return $origin . (string) ($parts['path'] ?? '/') . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $lastSlash = strrpos($path, '/');
        $directory = str_ends_with($path, '/') ? $path : substr($path, 0, $lastSlash === false ? 0 : $lastSlash + 1);
        return $origin . $directory . $location;
    }

    private function assertSafeHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $name) || preg_match('/[\r\n]/', (string) $value)) {
                throw new RuntimeException('Ungültiger HTTP-Header für Datenquelle.');
            }
        }
    }

    private function decodeJson(string $payload, string $url): mixed
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Ungültige JSON-Antwort von ' . (string) parse_url($url, PHP_URL_HOST), 0, $error);
        }
    }
}
